<?php
/**
 * CargoSQLQuery
 *
 * @author Yaron Koren
 * @ingroup Cargo
 */

class CargoSQLQuery {

	var $mTableNames;
	var $mFieldsStr;
	var $mWhere;
	var $mCargoJoinConds;
	var $mJoinConds;
	var $mAliasedFieldNames;
	var $mTableSchemas;
	var $mFieldDescriptions;
	var $mGroupBy;
	var $mOrderBy;
	var $mQueryLimit;

	/**
	 * This is newFromValues() instead of __construct() so that an
	 * object can be created without any values.
	 */
	public static function newFromValues( $tablesStr, $fieldsStr, $whereStr, $joinOnStr, $groupByStr, $orderByStr, $limitStr ) {
		global $wgCargoDefaultQueryLimit, $wgCargoMaxQueryLimit;

		$sqlQuery = new CargoSQLQuery();
		$sqlQuery->mTableNames = explode( ',', $tablesStr );
		$sqlQuery->mFieldsStr = $fieldsStr;
		$sqlQuery->mWhere = $whereStr;
		$sqlQuery->setCargoJoinConds( $joinOnStr );
		$sqlQuery->setAliasedFieldNames();
		$sqlQuery->mTableSchemas = CargoQuery::getTableSchemas( $sqlQuery->mTableNames );
		$sqlQuery->setDescriptionsForFields();
		$sqlQuery->handleVirtualFields();
		$sqlQuery->setMWJoinConds();
		$sqlQuery->mGroupBy = $groupByStr;
		$sqlQuery->mOrderBy = $orderByStr;
		$sqlQuery->mQueryLimit = $wgCargoDefaultQueryLimit;
		if ( $limitStr != '' ) {
			$sqlQuery->mQueryLimit = min( $limitStr, $wgCargoMaxQueryLimit );
		}
		$sqlQuery->addTablePrefixesToAll();

		return $sqlQuery;
	}

	/**
	 * Gets an array of field names and their aliases from the passed-in
	 * SQL fragment.
	 */
	function setAliasedFieldNames( ) {
		$this->mAliasedFieldNames = array();
		$fieldNames = CargoUtils::smartSplit( ',', $this->mFieldsStr );
		// Default is "_pageName".
		if ( count( $fieldNames ) == 0 ) {
			$fieldNames[] = '_pageName';
		}

		foreach ( $fieldNames as $i => $fieldName ) {
			$fieldNameParts = CargoUtils::smartSplit( '=', $fieldName );
			if ( count( $fieldNameParts ) == 2 ) {
				$fieldName = trim( $fieldNameParts[0] );
				$alias = trim( $fieldNameParts[1] );
			} else {
				// Might as well change underscores to spaces
				// by default - but for regular field names,
				// not the special ones.
				if ( $fieldName[0] != '_' ) {
					if ( strpos( $fieldName, '.' ) !== false ) {
						list( $tableName, $fieldName ) = explode( '.', $fieldName, 2 );
					}
					$alias = str_replace( '_', ' ', $fieldName );
				} else {
					$alias = $fieldName;
				}
			}
			$this->mAliasedFieldNames[$alias] = $fieldName;
		}
	}

	/**
	 * This does double duty: it both creates a "join conds" array
	 * from the string, and validates the set of join conditions
	 * based on the set of table names - making sure each table is
	 * joined.
	 *
	 * The "join conds" array created is not of the format that
	 * MediaWiki's database query() method requires - it is more
	 * structured and does not contain the necessary table prefixes yet.
	 */
	function setCargoJoinConds( $joinOnStr ) {
		$this->mCargoJoinConds = array();

		if ( trim( $joinOnStr ) == '' ) {
			if ( count( $this->mTableNames ) > 1 ) {
				throw new MWException( "Error: join conditions must be set for tables." );
			}
			return;
		}

		$joinStrings = explode( ',', $joinOnStr );
		foreach ( $joinStrings as $joinString ) {
			$containsEquals = strpos( $joinString, '=' );
			// Must be all-caps for now.
			$containsHolds = strpos( $joinString, ' HOLDS ' );
			if ( $containsEquals ) {
				$joinParts = explode( '=', $joinString );
			} elseif ( $containsHolds ) {
				$joinParts = explode( ' HOLDS ', $joinString );
			} else {
				throw new MWException( "Missing '=' in join condition ($joinString)." );
			}
			$joinPart1 = trim( $joinParts[0] );
			$tableAndField1 = explode( '.', $joinPart1 );
			if ( count( $tableAndField1 ) != 2 ) {
				throw new MWException( "Table and field name must both be specified in '$joinPart1'." );
			}
			list( $table1, $field1 ) = $tableAndField1;
			$joinPart2 = trim( $joinParts[1] );
			$tableAndField2 = explode( '.', $joinPart2 );
			if ( count( $tableAndField2 ) != 2 ) {
				throw new MWException( "Table and field name must both be specified in '$joinPart2'." );
			}
			list( $table2, $field2 ) = $tableAndField2;
			$joinCond = array(
				'joinType' => 'LEFT OUTER JOIN',
				'table1' => $table1,
				'field1' => $field1,
				'table2' => $table2,
				'field2' => $field2
			);
			if ( $containsHolds ) {
				$joinCond['holds'] = true;
			}
			$this->mCargoJoinConds[] = $joinCond;
		}

		// Now validate, to make sure that all the tables
		// are "joined" together. There's probably some more
		// efficient network algorithm for this sort of thing, but
		// oh well.
		$numUnmatchedTables = count( $this->mTableNames );
		$firstJoinCond = current( $this->mCargoJoinConds );
		$firstTableInJoins = $firstJoinCond['table1'];
		$matchedTables = array( $firstTableInJoins );
		do {
			$previousNumUnmatchedTables = $numUnmatchedTables;
			foreach( $this->mCargoJoinConds as $joinCond ) {
				$table1 = $joinCond['table1'];
				$table2 = $joinCond['table2'];
				if ( !in_array( $table1, $this->mTableNames ) ) {
					throw new MWException( "Error: table \"$table1\" is not in list of table names." );
				}
				if ( !in_array( $table2, $this->mTableNames ) ) {
					throw new MWException( "Error: table \"$table2\" is not in list of table names." );
				}

				if ( in_array( $table1, $matchedTables ) && !in_array( $table2, $matchedTables ) ) {
					$matchedTables[] = $table2;
					$numUnmatchedTables--;
				}
				if ( in_array( $table2, $matchedTables ) && !in_array( $table1, $matchedTables ) ) {
					$matchedTables[] = $table1;
					$numUnmatchedTables--;
				}
			}
		} while ( $numUnmatchedTables > 0 && $numUnmatchedTables > $previousNumUnmatchedTables );

		if ( $numUnmatchedTables > 0 ) {
			foreach ( $this->mTableNames as $tableName ) {
				if ( !in_array( $tableName, $matchedTables ) ) {
					throw new MWException( "Error: Table \"$tableName\" is not included within the join conditions." );
				}
			}
		}
	}

	/**
	 * Turn the very structured format that Cargo uses for join
	 * conditions into the one that MediaWiki uses - this includes
	 * adding the database prefix to each table name.
	 */
	function setMWJoinConds() {
		if ( $this->mCargoJoinConds == null ) {
			return;
		}

		$this->mJoinConds = array();
		foreach ( $this->mCargoJoinConds as $cargoJoinCond ) {
			$table2 = $cargoJoinCond['table2'];
			$this->mJoinConds[$table2] = array(
				$cargoJoinCond['joinType'],
				'cargo__' . $cargoJoinCond['table1'] . '.' .
					$cargoJoinCond['field1'] . '=' .
					'cargo__' . $cargoJoinCond['table2'] .
					'.' . $cargoJoinCond['field2']
			);
		}
	}

	/**
	 * Attempts to get the "field description" (type, etc.) of each field
	 * specified in a SELECT call (via a #cargo_query call), using the set
	 * of schemas for all data tables.
	 */
	function setDescriptionsForFields() {
		$this->mFieldDescriptions = array();
		foreach ( $this->mAliasedFieldNames as $alias => $fieldName ) {
			$tableName = null;
			if ( strpos( $fieldName, '.' ) !== false ) {
				// This could probably be done better with
				// regexps.
				list( $tableName, $fieldName ) = explode( '.', $fieldName, 2 );
			}
			// If it's a pre-defined field, we probably know the
			// type.
			if ( $fieldName == '_ID' || $fieldName == '_rowID' || $fieldName == '_pageID' ) {
				$description = array( 'type' => 'Integer' );
			} elseif ( $fieldName == '_pageName' ) {
				$description = array( 'type' => 'Page' );
			} elseif ( strpos( $tableName, '(' ) !== false || strpos( $fieldName, '(' ) !== false ) {
				$fieldNameParts = explode( '(', $fieldName );
				if ( count( $fieldNameParts ) > 1 ) {
					$probableFunction = strtolower( trim( $fieldNameParts[0] ) );
				} else {
					// Must be in the "table name", then.
					$tableNameParts = explode( '(', $tableName );
					$probableFunction = strtolower( trim( $tableNameParts[0] ) );
				}
				if ( in_array( $probableFunction, array( 'count', 'max', 'min', 'avg', 'sum', 'sqrt' ) ) ) {
					$description = array( 'type' => 'Integer' );
				} elseif ( in_array( $probableFunction, array( 'concat', 'lower', 'lcase', 'upper', 'ucase' ) ) ) {
					$description = array();
				} elseif ( in_array( $probableFunction, array( 'date', 'date_format', 'date_add', 'date_sub', 'date_diff' ) ) ) {
					$description = array( 'type' => 'Date' );
				}
			} else {
				// It's a standard field - though if it's
				// '_value', or ends in '__full', it's actually
				// the type of its corresponding field.
				if ( $fieldName == '_value' ) {
					if ( $tableName != null ) {
						list( $tableName, $fieldName ) = explode( '__', $tableName, 2 );
					} else {
						// There has to be exactly one
						// "field table" in the list
						// of tables.
						list( $tableName, $fieldName ) = explode( '__', $this->mTableSchemas['_fieldTables'][0] );
					}
				} elseif ( strlen( $fieldName ) > 6 && strpos( $fieldName, '__full', strlen( $fieldName ) - 6 ) !== false ) {
					$fieldName = substr( $fieldName, 0, strlen( $fieldName ) - 6 );
				}
				if ( $tableName != null ) {
					$description = $this->mTableSchemas[$tableName][$fieldName];
				} else {
					// Go through all the fields, until we
					// find the one matching this one.
					foreach ( $this->mTableSchemas as $curTableName => $tableSchema ) {
						if ( array_key_exists( $fieldName, $tableSchema ) ) {
							$description = $tableSchema[$fieldName];
							$tableName = $curTableName;
							break;
						}
					}
				}
			}
			// Fix alias.
			$alias = trim( $alias );
			$this->mFieldDescriptions[$alias] = $description;
			$this->mFieldDescriptions[$alias]['tableName'] = $tableName;
		}
	}

	function addToCargoJoinConds( $newCargoJoinConds ) {
		foreach ( $newCargoJoinConds as $newCargoJoinCond ) {
			// Go through to make sure it's not there already.
			$foundMatch = false;
			foreach ( $this->mCargoJoinConds as $cargoJoinCond ) {
				if ( $cargoJoinCond['table1'] == $newCargoJoinCond['table1'] &&
					$cargoJoinCond['field1'] == $newCargoJoinCond['field1'] &&
					$cargoJoinCond['table2'] == $newCargoJoinCond['table2'] &&
					$cargoJoinCond['field2'] == $newCargoJoinCond['field2'] ) {
					$foundMatch = true;
					continue;
				}
			}
			if ( !$foundMatch ) {
				$this->mCargoJoinConds[] = $newCargoJoinCond;
			}
		}
	}

	function addFieldTableToTableNames( $fieldTableName, $tableName ) {
		// Add it in in the correct place, if it should be added
		// at all.
		if ( in_array( $fieldTableName, $this->mTableNames ) ) {
			return;
		}
		if ( !in_array( $tableName, $this->mTableNames ) ) {
			// Show an error message here?
			return;
		}
		$indexOfMainTable = array_search( $tableName, $this->mTableNames );
		array_splice( $this->mTableNames, $indexOfMainTable + 1, 0, $fieldTableName );
	}

	function handleVirtualFields() {
		// The array-field alias can be found in the "where", "join on",
		// "fields" or "order by" clauses. Handling depends on which
		// clause it is:
		// "where" - make sure that "HOLDS" is specified. If it is,
		//     "translate" it, and add the values table to "tables" and
		//     "join on".
		// "join on" - make sure that "HOLDS" is specified, If it is,
		//     "translate" it, and add the values table to "tables".
		// "fields" - "translate" it, where the translation (i.e.
		//     the true field) depends on whether or not the values
		//     table is included.
		// "order by" - same as "field".

		// First, create an array of the virtual fields in the current
		// set of tables.
		$virtualFields = array();
		foreach ( $this->mTableSchemas as $tableName => $tableSchema ) {
			foreach ( $tableSchema as $fieldName => $fieldDescription ) {
				if ( array_key_exists( 'isList', $fieldDescription ) ) {
					$virtualFields[] = array(
						'fieldName' => $fieldName,
						'tableName' => $tableName
					);
				}
			}
		}

		// "where"
		$matches = array();
		foreach ( $virtualFields as $virtualField ) {
			$fieldName = $virtualField['fieldName'];
			$tableName = $virtualField['tableName'];
			$pattern1 = "/\b$tableName\.$fieldName (HOLDS)? /";
			$foundMatch = preg_match( $pattern1, $this->mWhere, $matches);
			$pattern2 = "/\b$fieldName (HOLDS)? /";
			$foundMatch2 = preg_match( $pattern2, $this->mWhere, $matches);
			if ( $foundMatch || $foundMatch2 ) {
				// @TODO - if no "HOLDS", throw an error.
				$fieldTableName = $tableName . '__' . $fieldName;
				$this->addFieldTableToTableNames( $fieldTableName, $tableName );
				$this->mCargoJoinConds[] = array(
					'joinType' => 'LEFT OUTER JOIN',
					'table1' => $tableName,
					'field1' => '_ID',
					'table2' => $fieldTableName,
					'field2' => '_rowID'
				);
				if ( $foundMatch ) {
					$this->mWhere = preg_replace( $pattern1, "$fieldTableName._value=", $this->mWhere );
				} elseif ( $foundMatch2 ) {
					$this->mWhere = preg_replace( $pattern2, "$fieldTableName._value=", $this->mWhere );
				}
			}
		}

		// "join on"
		$newCargoJoinConds = array();
		foreach ( $this->mCargoJoinConds as $i => $joinCond ) {
			if ( ! array_key_exists( 'holds', $joinCond ) ) {
				continue;
			}

			foreach ( $virtualFields as $virtualField ) {
				$fieldName = $virtualField['fieldName'];
				$tableName = $virtualField['tableName'];
				if ( $fieldName != $joinCond['field1'] || $tableName != $joinCond['table1'] ) {
					continue;
				}
				$fieldTableName = $tableName . '__' . $fieldName;
				$this->addFieldTableToTableNames( $fieldTableName, $tableName );
				$newJoinCond = array(
					'joinType' => 'LEFT OUTER JOIN',
					'table1' => $tableName,
					'field1' => '_ID',
					'table2' => $fieldTableName,
					'field2' => '_rowID'
				);
				$newCargoJoinConds[] = $newJoinCond;
				$newJoinCond2 = array(
					'joinType' => 'LEFT OUTER JOIN',
					'table1' => $fieldTableName,
					'field1' => '_value',
					'table2' => $this->mCargoJoinConds[$i]['table2'],
					'field2' => $this->mCargoJoinConds[$i]['field2']
				);
				$newCargoJoinConds[] = $newJoinCond2;
				// Is it safe to unset an array value while
				// cycling through the array? Hopefully.
				unset( $this->mCargoJoinConds[$i] );
			}
		}
		$this->addToCargoJoinConds( $newCargoJoinConds );

		// "fields"
		foreach ( $this->mAliasedFieldNames as $alias => $fieldName ) {
			$fieldDescription = $this->mFieldDescriptions[$alias];

			if ( strpos( $fieldName, '.' ) !== false ) {
				// This could probably be done better with
				// regexps.
				list( $tableName, $fieldName ) = explode( '.', $fieldName, 2 );
			} else {
				$tableName = $fieldDescription['tableName'];
			}

			// We're only interested in virtual list fields.
			$isVirtualField = false;
			foreach ( $virtualFields as $virtualField ) {
				if ( $fieldName == $virtualField['fieldName'] && $tableName == $virtualField['tableName'] ) {
					$isVirtualField = true;
					break;
				}
			}
			if ( !$isVirtualField ) {
				continue;
			}

			// Since the field name is an alias, it should get
			// translated, to either the "full" equivalent or to
			// the "value" field in the field table - depending on
			// whether or not that field has been "joined" on.
			$fieldTableName = $tableName . '__' . $fieldName;
			$fieldTableIsIncluded = false;
			foreach ( $this->mCargoJoinConds as $cargoJoinCond ) {
				if ( $cargoJoinCond['table1'] == $fieldTableName || $cargoJoinCond['table2'] == $fieldTableName ) {
					$fieldTableIsIncluded = true;
					break;
				}
			}

			if ( $fieldTableIsIncluded ) {
				$fieldName = $fieldTableName . '._value';
			} else {
				$fieldName .= '__full';
			}
			$this->mAliasedFieldNames[$alias] = $fieldName;
		}

		// "order by"
	}

	function addTablePrefixesToAll() {
		// We need to do the replacement for every element except
		// 'tables' and 'join on' - for 'tables', the prefix is
		// prepended automatically by the MediaWiki query, while for
		// 'join on' the prefixes are added when the object is created.
		foreach ( $this->mAliasedFieldNames as $alias => $fieldName ) {
			$this->mAliasedFieldNames[$alias] = self::addTablePrefixes( $fieldName );
		}
		if ( !is_null( $this->mWhere ) ) {
			$this->mWhere = self::addTablePrefixes( $this->mWhere );
		}
		$this->mGroupBy = self::addTablePrefixes( $this->mGroupBy );
		$this->mOrderBy = self::addTablePrefixes( $this->mOrderBy );
	}

	/**
	 * Calls a database SELECT query given the parts of the query; first
	 * appending the Cargo prefix onto table names where necessary.
	 */
	function run() {
		$cdb = CargoUtils::getDB();

		foreach ( $this->mTableNames as $tableName ) {
			if ( ! $cdb->tableExists( $tableName ) ) {
				throw new MWException( "Error: no database table exists for \"$tableName\"." );
			}
		}

		$selectOptions = array();

		if ( $this->mGroupBy != '' ) {
			$selectOptions['GROUP BY'] = $this->mGroupBy;
		}
		if ( $this->mOrderBy == '' ) {
			// By default, sort on the first field.
			reset( $this->mAliasedFieldNames );
			$this->mOrderBy = current( $this->mAliasedFieldNames );
		}
		$selectOptions['ORDER BY'] = $this->mOrderBy;
		$selectOptions['LIMIT'] = $this->mQueryLimit;

		// Aliases need to be surrounded by quotes when we actually
		// call the DB query.
		$realAliasedFieldNames = array();
		foreach ( $this->mAliasedFieldNames as $alias => $fieldName ) {
			$realAliasedFieldNames['"' . $alias . '"'] = $fieldName;
		}

		$res = $cdb->select( $this->mTableNames, $realAliasedFieldNames, $this->mWhere, __METHOD__, $selectOptions, $this->mJoinConds );

		// Is there a more straightforward way of turning query
		// results into an array?
		$resultArray = array();
		while ( $row = $cdb->fetchRow( $res ) ) {
			$resultsRow = array();
			foreach( $this->mAliasedFieldNames as $alias => $fieldName ) {
				// Escape any HTML, to avoid JavaScript
				// injections and the like.
				$resultsRow[$alias] = htmlspecialchars( $row[$alias] );
			}
			$resultArray[] = $resultsRow;
		}
		$cdb->close();

		return $resultArray;
	}

	function addTablePrefixes( $string ) {
		// Create arrays for doing replacements of table names within
		// the SQL by their "real" equivalents.
		$tableNamePatterns = array();
		foreach ( $this->mTableNames as $tableName ) {
			// Is there a way to do this with just one regexp?
			$tableNamePatterns[] = "/^$tableName\./";
			$tableNamePatterns[] = "/(\W)$tableName\./";
			$tableNameReplacements[] = "cargo__$tableName.";
			$tableNameReplacements[] = "$1cargo__$tableName.";
		}

		return preg_replace( $tableNamePatterns, $tableNameReplacements, $string ); 
	}

}
