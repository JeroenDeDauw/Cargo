<?php
/**
 * Displays an interface to let users recreate data via the Cargo
 * extension.
 *
 * @author Yaron Koren
 * @ingroup Cargo
 */

class CargoRecreateData extends IncludableSpecialPage {
	var $mTemplateTitle;
	var $mTableName;
	var $mIsDeclared;

	function __construct( $templateTitle, $tableName, $isDeclared ) {
		parent::__construct( 'RecreateData', 'recreatedata' );
		$this->mTemplateTitle = $templateTitle;
		$this->mTableName = $tableName;
		$this->mIsDeclared = $isDeclared;
	}

	function execute( $query = null ) {
		$out = $this->getOutput();

		if ( ! $this->getUser()->isAllowed( 'recreatedata' ) ) {
			$out->permissionRequired( 'recreatedata' );
			return;
		}

		$this->setHeaders();

		if ( !CargoUtils::tableExists( $this->mTableName ) ) {
			$out->setPageTitle( wfMessage( 'cargo-createdatatable' )->parse() );
		}

		if ( empty( $this->mTemplateTitle ) ) {
			// No template.
			// TODO - show an error message.
			return true;
		}

		$formSubmitted = $this->getRequest()->getText( 'submitted' ) == 'yes';
		if ( $formSubmitted ) {
			// Recreate the data!
			$this->recreateData();
			$out->redirect( $this->mTemplateTitle->getFullURL() );
			return true;
		}

		// Simple form.
		// Add in a little bit of JS to make sure that the button
		// isn't accidentally pressed twice.
		$text = '<form method="post" onSubmit="submitButton.disabled = true; return true;">';
		$text .= Html::element( 'p', null, wfMessage( 'cargo-recreatedata-desc' )->parse() );
		$text .= Html::hidden( 'action', 'recreatedata' ) . "\n";
		$text .= Html::hidden( 'submitted', 'yes' ) . "\n";

		$text .= Html::input( 'submitButton', wfMessage( 'ok' )->parse(), 'submit' );
		$text .= "\n</form>";

		$out->addHTML( $text );

		return true;
	}

	function callPopulateTableJobsForTemplate( $templateTitle, $jobParams ) {
		// We need to break this up into batches, to avoid running out
		// of memory for large page sets.
		// @TODO For *really* large page sets, it might make sense
		// to create a job for each batch.
		$offset = 0;
		do {
			$jobs = array();
			$titlesWithThisTemplate = $templateTitle->getTemplateLinksTo( array( 'LIMIT' => 500, 'OFFSET' => $offset ) );
			foreach ( $titlesWithThisTemplate as $titleWithThisTemplate ) {
				$jobs[] = new CargoPopulateTableJob( $titleWithThisTemplate, $jobParams );
			}
			$offset += 500;
			JobQueueGroup::singleton()->push( $jobs );
		} while ( count( $titlesWithThisTemplate ) >= 500 );
	}

	/**
	 * Calls jobs to drop and recreate the table(s) for this template.
	 */
	function recreateData() {
		// If this template calls #cargo_declare (as opposed to
		// #cargo_attach), drop and re-generate the Cargo DB table
		// for it.`
		if ( $this->mIsDeclared ) {
			$job = new CargoRecreateTablesJob( $this->mTemplateTitle );
			JobQueueGroup::singleton()->push( $job );
		}

		// Now create a job, CargoPopulateTable, for each page
		// that calls this template.
		$jobParams = array(
			'dbTableName' => $this->mTableName,
			'replaceOldRows' => !$this->mIsDeclared
		);

		$this->callPopulateTableJobsForTemplate( $this->mTemplateTitle, $jobParams );

		// If this template calls #cargo_declare, see if any templates
		// have attached themselves to this table, and if so, call
		// this job for their pages as well.
		if ( $this->mIsDeclared ) {
			$dbr = wfGetDB( DB_SLAVE );
			$res = $dbr->select( 'page_props',
				array(
					'pp_page'
				),
				array(
					'pp_value' => $this->mTableName,
					'pp_propname' => 'CargoAttachedTable'
				)
			);
			while ( $row = $dbr->fetchRow( $res ) ) {
				$templateID = $row['pp_page'];
				$attachedTemplateTitle = Title::newFromID( $templateID );
				$jobParams = array(
					'dbTableName' => $this->mTableName,
					'replaceOldRows' => false
				);

				$this->callPopulateTableJobsForTemplate( $attachedTemplateTitle, $jobParams );
			}
		}
	}

	/**
	 * Don't list this in Special:SpecialPages.
	 */
	function isListed() {
		return false;
	}
}
