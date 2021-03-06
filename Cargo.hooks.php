<?php
/**
 * CargoHooks class
 *
 * @author Yaron Koren
 * @ingroup Cargo
 */

class CargoHooks {

	/**
	 * @TODO - move this to a different class, like CargoUtils?
	 */
	public static function deletePageFromSystem( $pageID ) {
		// We'll delete every reference to this page in the
		// Cargo tables - in the data tables as well as in
		// cargo_pages. (Though we need the latter to be able to
		// efficiently delete from the former.)

		// Get all the "main" tables that this page is contained in.
		$dbr = wfGetDB( DB_MASTER );
		$cdb = CargoUtils::getDB();
		$res = $dbr->select( 'cargo_pages', 'table_name', array( 'page_id' => $pageID ) );
		while ( $row = $dbr->fetchRow( $res ) ) {
			$curMainTable = $row['table_name'];

			// First, delete from the "field" tables.
			$res2 = $dbr->select( 'cargo_tables', 'field_tables', array( 'main_table' => $curMainTable ) );
			$row2 = $dbr->fetchRow( $res2 );
			$fieldTableNames = unserialize( $row2['field_tables'] );
			foreach ( $fieldTableNames as $curFieldTable ) {
				// Thankfully, the MW DB API already provides a
				// nice method for deleting based on a join.
				$cdb->deleteJoin( $curFieldTable, $curMainTable, '_rowID', '_ID', array( '_pageID' => $pageID ) );
			}

			// Now, delete from the "main" table.
			$cdb->delete( $curMainTable, array( '_pageID' => $pageID ) );
		}

		// Finally, delete from cargo_pages.
		$dbr->delete( 'cargo_pages', array( 'page_id' => $pageID ) );

		// This call is needed to get deletions to actually happen.
		$cdb->close();
	}

	/**
	 * Called by the MediaWiki 'PageContentSave' hook.
	 */
	public static function onPageContentSave( $wikiPage, $user, $content, $summary, $isMinor, $isWatch, $section, $flags, $status ) {
		// First, delete the existing data.
		$pageID = $wikiPage->getID();
		self::deletePageFromSystem( $pageID );

		// Now parse the page again, so that #cargo_store will be
		// called.
		// Even though the page will get parsed again after the save,
		// we need to parse it here anyway, for the settings we
		// added to remain set.
		CargoStore::$settings['origin'] = 'page save';
		global $wgParser;
		$title = $wikiPage->getTitle();
		$wgParser->parse( $content->getNativeData(), $title, new ParserOptions() );
		return true;
	}

	public static function onTitleMoveComplete( Title &$title, Title &$newtitle, User &$user, $oldid, $newid, $reason ) {
		// For each main data table to which this page belongs, change
		// the page name.
		$newPageName = $newtitle->getPrefixedText();
		$dbr = wfGetDB( DB_MASTER );
		$cdb = CargoUtils::getDB();
		// We use $oldid, because that's the page ID - $newid is the
		// ID of the redirect page.
		// @TODO - do anything with the redirect?
		$res = $dbr->select( 'cargo_pages', 'table_name', array( 'page_id' => $oldid ) );
		while ( $row = $dbr->fetchRow( $res ) ) {
			$curMainTable = $row['table_name'];
			$cdb->update( $curMainTable, array( '_pageName' => $newPageName ), array( '_pageID' => $oldid ) );
		}

		return true;
	}

	/**
	 * Deletes all Cargo data about a page, if the page has been deleted.
	 */
	public static function onArticleDeleteComplete( &$article, User &$user, $reason, $id, $content, $logEntry ) {
		self::deletePageFromSystem( $id );
		return true;
	}

	public static function describeDBSchema( $updater = null ) {
		$dir = dirname( __FILE__ );

		// DB updates
		// For now, there's just a single SQL file for all DB types.
		if ( $updater === null ) {
			global $wgExtNewTables, $wgDBtype;
			//if ( $wgDBtype == 'mysql' ) {
				$wgExtNewTables[] = array( 'cargo_tables', "$dir/Cargo.sql" );
				$wgExtNewTables[] = array( 'cargo_pages', "$dir/Cargo.sql" );
			//}
		} else {
			//if ( $updater->getDB()->getType() == 'mysql' ) {
				$updater->addExtensionUpdate( array( 'addTable', 'cargo_tables', "$dir/Cargo.sql", true ) );
				$updater->addExtensionUpdate( array( 'addTable', 'cargo_pages', "$dir/Cargo.sql", true ) );
			//}
		}
		return true;
	}

}
