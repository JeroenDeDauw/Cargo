<?php
/**
 * Displays an interface to let users recreate data via the Cargo
 * extension.
 *
 * @author Yaron Koren
 * @ingroup Cargo
 */

class CargoPageValues extends IncludableSpecialPage {
	var $mTitle;

	function __construct( $title ) {
		parent::__construct( 'PageValues' );

		$this->mTitle = $title;
	}

	function execute( $subpage = false ) {
		$out = $this->getOutput();

		$this->setHeaders();

		$pageName = $this->mTitle->getPrefixedText();
		$out->setPageTitle( wfMessage( 'cargo-pagevaluesfor', $pageName )->text() );

		$text = '';

		$dbr = wfGetDB( DB_MASTER );
		$res = $dbr->select( 'cargo_pages', 'table_name', array( 'page_id' => $this->mTitle->getArticleID() ) );
		while ( $row = $dbr->fetchRow( $res ) ) {
			$tableName = $row['table_name'];
			$queryResults = $this->getRowsForPageInTable( $tableName );
			$text .= Html::element( 'h2', null, wfMessage( 'cargo-pagevalues-tablevalues', $tableName )->text() ) . "\n";
			foreach ( $queryResults as $rowValues ) {
				$tableContents = '';
				foreach ( $rowValues as $field => $value ) {
					$tableContents .= $this->printRow( $field, $value );
				}
				$text .= $this->printTable( $tableContents );
			}
		}
		$out->addHTML( $text );

		return true;
	}

	function getRowsForPageInTable( $tableName ) {
		global $wgParser;

		$sqlQuery = new CargoSQLQuery();
		$sqlQuery->mTableNames = array( $tableName );

		$tableSchemas = CargoQuery::getTableSchemas( array( $tableName ) );
		$sqlQuery->mTableSchemas = $tableSchemas;

		$aliasedFieldNames = array();
		foreach( $tableSchemas[$tableName] as $fieldName => $fieldDescription ) {
			if ( array_key_exists( 'hidden', $fieldDescription ) ) {
				// do some custom formatting
			}

			$fieldAlias = str_replace( '_', ' ', $fieldName );

			if ( array_key_exists( 'isList', $fieldDescription ) ) {
				$aliasedFieldNames[$fieldAlias] = $fieldName . '__full';
			} elseif ( $fieldDescription['type'] == 'Coordinates' ) {
				$aliasedFieldNames[$fieldAlias] = $fieldName . '__full';
			} else {
				$aliasedFieldNames[$fieldAlias] = $fieldName;
			}
		}

		$sqlQuery->mAliasedFieldNames = $aliasedFieldNames;
		$sqlQuery->setDescriptionsForFields();
		$sqlQuery->mWhere = "_pageID = " . $this->mTitle->getArticleID();

		$queryResults = $sqlQuery->run();
		$formattedQueryResults = CargoQuery::getFormattedQueryResults( $queryResults, $sqlQuery->mFieldDescriptions, $wgParser );
		return $formattedQueryResults;
	}

	/**
	 * Based on MediaWiki's InfoAction::addRow()
	 */
	function printRow( $name, $value ) {
		return Html::rawElement( 'tr', array(),
			Html::rawElement( 'td', array( 'style' => 'vertical-align: top;' ), $name ) .
			Html::rawElement( 'td', array(), $value )
		);
	}

	/**
	 * Based on MediaWiki's InfoAction::addTable()
	 */
	function printTable( $tableContents ) {
		return Html::rawElement( 'table', array( 'class' => 'wikitable mw-page-info' ),
			$tableContents ) . "\n";
	}

	/**
	 * Don't list this in Special:SpecialPages.
	 */
	function isListed() {
		return false;
	}
}
