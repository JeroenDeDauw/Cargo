<?php
/**
 * Shows the results of a Cargo query.
 *
 * @author Yaron Koren
 * @ingroup Cargo
 */

class CargoViewData extends SpecialPage {

	/**
	 * Constructor
	 */
	function __construct() {
		parent::__construct( 'ViewData' );
	}

	function execute( $query ) {
		$this->setHeaders();
		$out = $this->getOutput();
		$out->addModuleStyles( 'ext.cargo.main' );
		list( $limit, $offset ) = wfCheckLimits();
		$rep = new ViewDataPage();
		return $rep->execute( $query );
	}
}

class ViewDataPage extends QueryPage {
	public function __construct( $name = 'ViewData' ) {
		parent::__construct( $name );

		$req = $this->getRequest();
		$tablesStr = $req->getVal( 'tables' );
		$fieldsStr = $req->getVal( 'fields' );
		$whereStr = $req->getVal( 'where' );
		$joinOnStr = $req->getVal( 'join_on' );
		$groupByStr = $req->getVal( 'group_by' );
		$orderByStr = $req->getVal( 'order_by' );

		$limitStr = null;

		$this->sqlQuery = CargoSQLQuery::newFromValues( $tablesStr, $fieldsStr, $whereStr, $joinOnStr, $groupByStr, $orderByStr, $limitStr );

		$formatStr = $req->getVal( 'format' );
		$this->format = $formatStr;

		// This is needed for both the results display and the
		// navigation links.
		$this->displayParams = array();
		$queryStringValues = $this->getRequest()->getValues();
		foreach( $queryStringValues as $key => $value ) {
			if ( !in_array( $key, array( 'title', 'tables', 'fields', 'join_on', 'order_by', 'group_by', 'format', 'offset' ) ) ) {
				$this->displayParams[$key] = $value;
			}
		}
	}

	function getName() {
		return "ViewData";
	}

	function isExpensive() { return false; }

	function isSyndicated() { return false; }

	function getPageHeader() {
		$header = '<p>' . 'Results:' . "</p><br />\n";
		return $header;
	}

	function getPageFooter() {
	}

	function getRecacheDB() {
		return CargoUtils::getDB();
	}

	function getQueryInfo() {
		$selectOptions = array();
		if ( $this->sqlQuery->mGroupBy != '' ) {
			$selectOptions['GROUP BY'] = $this->sqlQuery->mGroupBy;
		}
		// "order by" is handled elsewhere, in getOrderFields().

		// Field aliases need to have quotes placed around them
		// before actually running the query.
		$realAliasedFieldNames = array();
		foreach ( $this->sqlQuery->mAliasedFieldNames as $alias => $fieldName ) {
			$realAliasedFieldNames['"' . $alias . '"'] = $fieldName;
		}

		$queryInfo = array(
			'tables' => $this->sqlQuery->mTableNames,
			'fields' => $realAliasedFieldNames,
			'options' => $selectOptions
		);
		if ( $this->sqlQuery->mWhere != '' ) {
			$queryInfo['conds'] = $this->sqlQuery->mWhere;
		}
		if ( !empty( $this->sqlQuery->mJoinConds ) ) {
			$queryInfo['join_conds'] = $this->sqlQuery->mJoinConds;
		}
		return $queryInfo;
	}

	function linkParameters() {
		$possibleParams = array( 'tables', 'fields', 'where', 'join_on', 'order_by', 'group_by', 'format' );
		$linkParams = array();
		$req = $this->getRequest();
		foreach ( $possibleParams as $possibleParam ) {
			if ( $req->getCheck( $possibleParam ) ) {
				$linkParams[$possibleParam] = $req->getVal( $possibleParam );
			}
		}

		foreach ( $this->displayParams as $key => $value ) {
			$linkParams[$key] = $value;
		}

		return $linkParams;
	}

	function getOrderFields() {
		if ( $this->sqlQuery->mOrderBy != '' ) {
			return array( $this->sqlQuery->mOrderBy );
		}
	}

	function sortDescending() {
		return false;
	}

	function formatResult( $skin, $result ) {
		// This function needs to be declared, but it is not called.
	}

	/**
	 */
	function outputResults( $out, $skin, $dbr, $res, $num, $offset ) {
		$valuesTable = array();
		for ( $i = 0; $i < $num && $row = $res->fetchObject(); $i++ ) {
			$valuesTable[] = get_object_vars( $row );
		}
		$formattedValuesTable = CargoQuery::getFormattedQueryResults( $valuesTable, $this->sqlQuery->mFieldDescriptions, null );
		$formatClass = CargoQuery::getFormatClass( $this->format, $this->sqlQuery->mFieldDescriptions );
		$formatObject = new $formatClass();
		$this->displayParams['offset'] = $offset;
		$html = $formatObject->display( $valuesTable, $formattedValuesTable, $this->sqlQuery->mFieldDescriptions, $this->displayParams );
		$out->addHTML( $html );
		return;
	}

}
