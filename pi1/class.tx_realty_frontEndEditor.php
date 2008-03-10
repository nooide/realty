<?php
/***************************************************************
* Copyright notice
*
* (c) 2008 Saskia Metzler <saskia@merlin.owl.de> All rights reserved
*
* This script is part of the TYPO3 project. The TYPO3 project is
* free software; you can redistribute it and/or modify
* it under the terms of the GNU General Public License as published by
* the Free Software Foundation; either version 2 of the License, or
* (at your option) any later version.
*
* The GNU General Public License can be found at
* http://www.gnu.org/copyleft/gpl.html.
*
* This script is distributed in the hope that it will be useful,
* but WITHOUT ANY WARRANTY; without even the implied warranty of
* MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
* GNU General Public License for more details.
*
* This copyright notice MUST APPEAR in all copies of the script!
***************************************************************/

/**
 * Class 'tx_realty_frontEndEditor' for the 'realty' extension. This class
 * provides a FE editor the realty plugin.
 *
 * @package		TYPO3
 * @subpackage	tx_realty
 * @author		Saskia Metzler <saskia@merlin.owl.de>
 */

require_once(PATH_formidableapi);

require_once(t3lib_extMgm::extPath('oelib').'class.tx_oelib_templatehelper.php');

require_once(t3lib_extMgm::extPath('realty').'lib/tx_realty_constants.php');
require_once(t3lib_extMgm::extPath('realty').'lib/class.tx_realty_object.php');

class tx_realty_frontEndEditor extends tx_oelib_templatehelper {
	/** the extension key (FORMidable expects this to be public) */
	public $extKey = 'realty';

	/** plugin in which the FE editor is used */
	private $plugin = null;

	/** formidable object that creates the form */
	private $formCreator = null;

	/** instance of tx_realty_object */
	private $realtyObject = null;

	/**
	 * UID of the currently edited object, zero if the object is going to be a
	 * new database record.
	 */
	private $realtyObjectUid = 0;

	/** locale convention array */
	private $localeConvention = array();

	/** whether the constructor is called in test mode */
	private $isTestMode = false;

	/**
	 * The constructor.
	 *
	 * @param	tx_oelib_templatehelper		plugin which uses this FE editor
	 * @param	integer		UID of the object to edit, set to 0 to create a new
	 * 						database record, must not be negative
	 * @param	boolean		whether the FE editor is instanciated in test mode
	 */
	public function __construct(
		tx_oelib_templatehelper $plugin, $uidOfObjectToEdit, $isTestMode = false
	) {
		$this->isTestMode = $isTestMode;
		$this->realtyObjectUid = $uidOfObjectToEdit;

		$this->realtyObject = t3lib_div::makeInstance('tx_realty_object');
		// The parameter 'true' ensures only enabled objects to become loaded.
		$this->realtyObject->loadRealtyObject($this->realtyObjectUid, true);

		$this->plugin = $plugin;
		// For the templatehelper's functions about setting labels and filling
		// markers, the plugin's templatehelper object is used as the inherited
		// templatehelper does not have all configuration which would be
		// necessary for this.
		$this->plugin->getTemplateCode();
		$this->plugin->setLabels();
		// For configuration stuff the own inherited templatehelper can be used.
		$this->init($this->plugin->getConfiguration());
		$this->pi_initPIflexForm();

		$this->formCreator = t3lib_div::makeInstance('tx_ameosformidable');
		// The FORMidable object is not initialized for testing.
		if (!$this->isTestMode) {
			$this->formCreator->init(
				$this,
				t3lib_extMgm::extPath('realty').'pi1/tx_realty_frontEndEditor.xml',
				($this->realtyObjectUid > 0) ? $this->realtyObjectUid : false
			);
		}
	}


	////////////////////////////////////////
	// Functions concerning the rendering.
	////////////////////////////////////////

	/**
	 * Returns the FE editor in HTML if a user is logged in and authorized, and
	 * if the object to edit actually exists in the database. Otherwise the
	 * result will be an error view.
	 *
	 * @return	string		HTML for the FE editor or an error view if the
	 * 						requested object is not editable for the current user
	 */
	public function render() {
		if (!$this->realtyObjectExistsInDatabase()) {
			return $this->renderObjectDoesNotExistMessage();
		}
		if (!$this->isLoggedIn()) {
			return $this->renderPleaseLogInMessage();
		}
		if (!$this->isFrontEndUserAuthorized()) {
			return $this->renderNoAccessMessage();
		}

		return $this->formCreator->render();
	}

	/**
	 * Returns the HTML for an error view. Therefore the plugin's
	 * template is used.
	 *
	 * @param	string		content for the error view, must not be empty
	 *
	 * @return	string		HTML of the error message, will not be empty
	 */
	private function renderErrorMessage($rawErrorMessage) {
		$this->plugin->setMarkerContent('error_message', $rawErrorMessage);

		return $this->plugin->getSubpart('FRONT_END_EDITOR');
	}

	/**
	 * Returns HTML for the object-does-not-exist error message.
	 *
	 * @return	string		HTML for the object-does-not-exist error message
	 */
	private function renderObjectDoesNotExistMessage() {
		header('Status: 404 Not Found');

		return $this->renderErrorMessage(
			$this->plugin->translate('message_noResultsFound_fe_editor')
		);
	}

	/**
	 * Returns HTML for the please-login error message.
	 *
	 * @return	string		HTML for the please-login error message
	 */
	private function renderPleaseLogInMessage() {
		$redirectUrl = t3lib_div::locationHeaderUrl(
			$this->plugin->pi_linkTP_keepPIvars_url()
		);
		$link = $this->plugin->cObj->getTypoLink(
			htmlspecialchars($this->plugin->translate('message_please_login')),
			$this->plugin->getConfValueInteger('loginPID'),
			array('redirect_url' => $redirectUrl)
		);

		return $this->renderErrorMessage($link);
	}

	/**
	 * Returns HTML for the access-denied error message.
	 *
	 * @return	string		HTML for the access-denied error message
	 */
	private function renderNoAccessMessage() {
		header('Status: 401 Unauthorized');

		return $this->renderErrorMessage(
			$this->plugin->translate('message_access_denied')
		);
	}

	/**
	 * Checks whether the reatly object exists in the database and is enabled.
	 * For new objects, the result will always be true.
	 *
	 * @return	boolean		true if the realty object is available for editing,
	 * 						false otherwise
	 */
	private function realtyObjectExistsInDatabase() {
		if ($this->realtyObjectUid == 0) {
			return true;
		}

		return !$this->realtyObject->isRealtyObjectDataEmpty();
	}

	/**
	 * Checks whether the FE user is allowed to edit the object. New objects are
	 * considered to be editable by every logged in user.
	 *
	 * Note: This function does not check on user group memberships.
	 *
	 * @return	boolean		true if the FE user is allowed to edit the object,
	 * 						false otherwise
	 */
	private function isFrontEndUserAuthorized() {
		if ($this->realtyObjectUid == 0) {
			return true;
		}

		return $this->realtyObject->getProperty('owner') == $this->getFeUserUid();
	}


	////////////////////////////////
	// Functions used by the form.
	////////////////////////////////

	/**
	 * Returns the URL where to redirect to after saving a record.
	 *
	 * @return	string		complete URL of the configured FE page, if none is
	 * 						configured, the redirect will lead to the base URL
	 */
	public function getRedirectUrl() {
		return t3lib_div::locationHeaderUrl($this->plugin->cObj->getTypoLink_URL(
			$this->plugin->getConfValueInteger('feEditorRedirectPid')
		));
	}

	/**
	 * Returns a localized message that the number entered in the provided field
	 * is not valid.
	 *
	 * @param	array	 	form data, must contain the key 'fieldName', the
	 * 						value of 'fieldName' must be a database column name
	 * 						of 'tx_realty_objects' which concerns the message,
	 * 						must not be empty
	 *
	 * @return	string		localized message following the pattern
	 * 						"[field name]: [invalid number message]"
	 */
	public function getNoValidNumberMessage(array $formData) {
		return $this->getMessageForRealtyObjectField(
			$formData['fieldName'],
			'message_no_valid_number'
		);
	}

	/**
	 * Returns a localized message that the price entered in the provided field
	 * is not valid.
	 *
	 * @param	array	 	form data, must contain the key 'fieldName', the
	 * 						value of 'fieldName' must be a database column name
	 * 						of 'tx_realty_objects' which concerns the message,
	 * 						must not be empty
	 *
	 * @return	string		localized message following the pattern
	 * 						"[field name]: [invalid price message]"
	 */
	public function getNoValidPriceMessage(array $formData) {
		return $this->getMessageForRealtyObjectField(
			$formData['fieldName'],
			'message_no_valid_price'
		);
	}

	/**
	 * Returns a localized message that the year entered in the provided field
	 * is not valid.
	 *
	 * @param	array	 	form data, must contain the key 'fieldName', the
	 * 						value of 'fieldName' must be a database column name
	 * 						of 'tx_realty_objects' which concerns the message,
	 * 						must not be empty
	 *
	 * @return	string		localized message following the pattern
	 * 						"[field name]: [invalid price message]"
	 */
	public function getNoValidYearMessage(array $formData) {
		return $this->getMessageForRealtyObjectField(
			$formData['fieldName'],
			'message_no_valid_year'
		);
	}

	/**
	 * Checks whether the object number is readonly.
	 *
	 * @return	boolean		true if the object number is readonly, false
	 * 						otherwise
	 */
	public function isObjectNumberReadonly() {
		return $this->realtyObjectUid > 0;
	}

	/**
	 * Checks whether a number is valid and does not have decimal digits.
	 *
	 * @param	array		array with one element named "value" that contains
	 * 						the number to check, this number may also be empty
	 *
	 * @return	boolean		true if the number is an integer or empty
	 */
	public function isValidIntegerNumber(array $valueToCheck) {
		return $this->isValidNumber($valueToCheck['value'], false);
	}

	/**
	 * Checks whether a number which may have decimal digits is valid.
	 *
	 * @param	array		array with one element named "value" that contains
	 * 						the number to check, this number may also be empty
	 *
	 * @return	boolean		true if the number is valid or empty
	 */
	public function isValidNumberWithDecimals(array $valueToCheck) {
		return $this->isValidNumber($valueToCheck['value'], true);
	}

	/**
	 * Checks whether the provided year is this year or earlier.
	 *
	 * @param	array		array with one element named "value" that contains
	 * 						the year to check, this must be this year or earlier
	 * 						or empty
	 *
	 * @return	boolean		true if the year is valid or empty
	 */
	public function isValidYear(array $valueToCheck) {
		return ($this->isValidNumber($valueToCheck['value'], false)
			&& ($valueToCheck['value'] <= date('Y', mktime())));
	}

	/**
	 * Adds administrative data and unifies numbers.
	 *
	 * @see	addAdministrativeData(), unifyNumbersToInsert()
	 *
	 * @param	array 		form data, may be empty
	 *
	 * @return	array		form data with additional administrative data and
	 * 						unified numbers
	 */
	public function modifyDataToInsert(array $formData) {
		return $this->addAdministrativeData(
			$this->unifyNumbersToInsert($formData)
		);
	}

	/**
	 * Fills the select box for city records.
	 *
	 * @return	array		items for the select box, will be empty if there are
	 * 						no matching records
	 */
	public function populateListOfCities() {
		return $this->populateListByTitleAndUid(REALTY_TABLE_CITIES);
	}

	/**
	 * Fills the select box for district records.
	 *
	 * @return	array		items for the select box, will be empty if there are
	 * 						no matching records
	 */
	public function populateListOfDistricts() {
		return $this->populateListByTitleAndUid(REALTY_TABLE_DISTRICTS);
	}

	/**
	 * Fills the select box for house type records.
	 *
	 * @return	array		items for the select box, will be empty if there are
	 * 						no matching records
	 */
	public function populateListOfHouseTypes() {
		return $this->populateListByTitleAndUid(REALTY_TABLE_HOUSE_TYPES);
	}

	/**
	 * Fills the select box for apartment type records.
	 *
	 * @return	array		items for the select box, will be empty if there are
	 * 						no matching records
	 */
	public function populateListOfApartmentTypes() {
		return $this->populateListByTitleAndUid(REALTY_TABLE_APARTMENT_TYPES);
	}

	/**
	 * Fills the select box for heating type records.
	 *
	 * @return	array		items for the select box, will be empty if there are
	 * 						no matching records
	 */
	public function populateListOfHeatingTypes() {
		return $this->populateListByTitleAndUid(REALTY_TABLE_HEATING_TYPES);
	}

	/**
	 * Fills the select box for car place records.
	 *
	 * @return	array		items for the select box, will be empty if there are
	 * 						no matching records
	 */
	public function populateListOfCarPlaces() {
		return $this->populateListByTitleAndUid(REALTY_TABLE_CAR_PLACES);
	}

	/**
	 * Fills the select box for state records.
	 *
	 * @return	array		items for the select box, will be empty if there are
	 * 						no matching records
	 */
	public function populateListOfConditions() {
		return $this->populateListByTitleAndUid(REALTY_TABLE_CONDITIONS);
	}

	/**
	 * Fills the select box for pet records.
	 *
	 * @return	array		items for the select box, will be empty if there are
	 * 						no matching records
	 */
	public function populateListOfPets() {
		return $this->populateListByTitleAndUid(REALTY_TABLE_PETS);
	}

	/**
	 * Fills the select box for languages.
	 *
	 * @return	array		items for the select box, will be empty if there are
	 * 						no matching records
	 */
	public function populateListOfLanguages() {
		return $this->populateList(
			'static_languages', 'lg_name_en', 'lg_iso_2', false
		);
	}


	/////////////////////////////////////////////////////////
	// Helper functions for the functions used by the form.
	/////////////////////////////////////////////////////////

	/**
	 * Returns a localized message for a certain field.
	 *
	 * @param	string		label of the field which concerns the the message,
	 * 						must be the absolute path starting with "LLL:EXT:",
	 * 						must not be empty
	 * @param	string		label of the message to return, must be defined in 
	 * 						pi1/locallang.xml, must not be empty
	 *
	 * @return	string		localized message following the pattern
	 * 						"[field name]: [message]"
	 */
	private function getMessageForField($labelOfField, $labelOfMessage) {
		$GLOBALS['LANG']->lang = $GLOBALS['TSFE']->lang;

		return $GLOBALS['LANG']->sL($labelOfField).': '
			.$this->plugin->translate($labelOfMessage);
	}

	/**
	 * Returns a localized validation error message.
	 *
	 * @param	string		name of a database column of 'tx_realty_objects'
	 * 						which concerns the the message, must not be empty
	 * @param	string		label of the message to return, must be the absolute
	 * 						path starting with "LLL:EXT:", must not be empty
	 *
	 * @return	string		localized message following the pattern
	 * 						"[field name]: [message]"
	 */
	private function getMessageForRealtyObjectField($fieldName, $messageLabel) {
		return $this->getMessageForField(
			'LLL:EXT:realty/locallang_db.xml:tx_realty_objects.'.$fieldName,
			$messageLabel
		);
	}

	/**
	 * Checks whether the a number is correctly formatted. The format must be
	 * according to the current locale.
	 *
	 * @param	string		value to check to be a valid number, may be empty
	 * @param	boolean		whether the number may have decimals
	 *
	 * @return	boolean		true if $valueToCheck is valid or empty, false
	 * 						otherwise
	 */
	private function isValidNumber($valueToCheck, $mayHaveDecimals) {
		if ($valueToCheck == '') {
			return true;
		}

		$unifiedValueToCheck = $this->unifyNumber($valueToCheck);

		if ($mayHaveDecimals) {
			$result = preg_match('/^[\d]*(\.[\d]{1,2})?$/', $unifiedValueToCheck);
		} else {
			$result = preg_match('/^[\d]*$/', $unifiedValueToCheck);
		}

		return (boolean) $result;
	}

	/**
	 * Unifies a number.
	 *
	 * Replaces a comma by a dot and strips whitespaces.
	 *
	 * @param	string		number to be unified, may be empty
	 *
	 * @return	string		unified number with a dot as decimal separator, will
	 * 						be empty if $number was empty
	 */
	private function unifyNumber($number) {
		if ($number == '') {
			return '';
		}

		$unifiedNumber = str_replace(',', '.', $number);

		return str_replace(' ', '', $unifiedNumber);
	}

	/**
	 * Unifies all numbers before they get inserted into the database.
	 *
	 * @param	array		data that will be inserted, may be empty
	 *
	 * @return	array		data that will be inserted with unified numbers,
	 * 						will be empty if an empty array was provided
	 */
	private function unifyNumbersToInsert(array $formData) {
		$modifiedFormData = $formData;
		$numericFields = array(
			'number_of_rooms',
			'living_area',
			'total_area',
			'estate_size',
			'rent_excluding_bills',
			'extra_charges',
			'year_rent',
			'floor',
			'floors',
			'bedrooms',
			'bathrooms',
			'garage_rent',
			'garage_price',
			'construction_year'
		);

		foreach ($numericFields as $key) {
			if (isset($modifiedFormData[$key])) {
				$modifiedFormData[$key] = $this->unifyNumber($modifiedFormData[$key]);
			}
		}

		return $modifiedFormData;
	}

	/**
	 * Adds some values to the form data before insertion into the database.
	 * Added values for new objects are: 'crdate', 'tstamp', 'pid' and 'owner'.
	 * For objects to update, just the 'tstamp' will be refreshed.
	 *
	 * @param	array		form data, may be empty
	 *
	 * @return	array		form data with additional elements: always 'tstamp',
	 * 						for new objects also 'crdate', 'pid' and 'owner'
	 */
	private function addAdministrativeData(array $formData) {
		$pidFromCity = $this->getPidFromCityRecord(intval($formData['city']));
		$modifiedFormData = $formData;

		$modifiedFormData['tstamp'] = mktime();
		// The PID might have changed if the city did.
		$modifiedFormData['pid'] = ($pidFromCity != 0) 
			? $pidFromCity
			: $this->getConfValueString('sysFolderForFeCreatedRecords');
		// New records need some additional data.
		if ($this->realtyObjectUid == 0) {
			$modifiedFormData['crdate'] = mktime();
			$modifiedFormData['owner'] = $this->getFeUserUid();
		}

		return $modifiedFormData;
	}

	/**
	 * Returns the PID from the field 'save_folder'. This PID defines where to
	 * store records for the city defined by $cityUid.
	 *
	 * @param	integer		UID of the city record from which to get the system
	 * 						folder ID, must be integer > 0
	 *
	 * @return	integer		UID of the system folder where to store this city's
	 * 						records, will be zero if no folder was set
	 */
	private function getPidFromCityRecord($cityUid) {
		$dbResult = $GLOBALS['TYPO3_DB']->exec_SELECTquery(
			'save_folder',
			REALTY_TABLE_CITIES,
			'uid='.$cityUid
		);
		if (!$dbResult) {
			throw new Exception('There was an error with the database query.');
		}

		$row = $GLOBALS['TYPO3_DB']->sql_fetch_assoc($dbResult);

		return intval($row['save_folder']);
	}

	/**
	 * Provides data items to fill select boxes. Returns caption-value pairs from
	 * the database table named $tableName.
	 * The field "title" will be returned within the array as caption. The UID
	 * will be the value.
	 *
	 * @param	string		the table name to query, must not be empty
	 * @param	boolean		whether the table has the column 'is_dummy_record'
	 * 						for the test mode flag
	 *
	 * @return	array		items for the select box, will be empty if there are
	 * 						no matching records
	 */
	private function populateListByTitleAndUid(
		$tableName, $hasTestModeColumn = true
	) {
		return $this->populateList($tableName, 'title', 'uid', $hasTestModeColumn);
	}

	/**
	 * Provides data items to fill select boxes. Returns caption-value pairs from
	 * the database table named $tableName.
	 *
	 * @param	string		the table name to query, must not be empty
	 * @param	string		name of the database column for the caption, must
	 * 						not be empty
	 * @param	string		name of the database column for the value, must not
	 * 						be empty
	 * @param	boolean		whether the table has the column 'is_dummy_record'
	 * 						for the test mode flag
	 *
	 * @return	array		items for the select box, will be empty if there are
	 * 						no matching records
	 */
	private function populateList(
		$tableName, $keyForCaption, $keyForValue, $hasTestModeColumn = true
	) {
		$items = array();
		$whereClause = '1=1';

		if ($this->isTestMode && $hasTestModeColumn) {
			$whereClause = 'is_dummy_record=1';
		}

		$dbResult = $GLOBALS['TYPO3_DB']->exec_SELECTquery(
			$keyForCaption.','.$keyForValue,
			$tableName,
			$whereClause.$this->enableFields($tableName),
			'',
			$keyForCaption
		);
		if (!$dbResult) {
			throw new Exception('There was an error with the database query.');
		}

		while ($row = $GLOBALS['TYPO3_DB']->sql_fetch_assoc($dbResult)) {
			$items[] = array(
				'caption' => $row[$keyForCaption], 'value' => $row[$keyForValue]
			);
		}

		// Resets the array pointer as the populateList* functions expect
		// arrays with a reset array pointer.
		reset($items);

		return $items;
	}


	///////////////////////////////////
	// Utility functions for testing.
	///////////////////////////////////

	/**
	 * Fakes the setting of the current UID.
	 *
	 * This function is for testing purposes.
	 *
	 * @param	integer		UID of the currently edited realty object, for
	 * 						creating a new database record, $uid must be zero,
	 * 						provided values must not be negative
	 */
	public function setRealtyObjectUid($uid) {
		$this->realtyObjectUid = $uid;
		$this->realtyObject->loadRealtyObject($this->realtyObjectUid, true);
	}
}

if (defined('TYPO3_MODE') && $TYPO3_CONF_VARS[TYPO3_MODE]['XCLASS']['ext/realty/pi1/class.tx_realty_frontEndEditor.php']) {
	include_once($TYPO3_CONF_VARS[TYPO3_MODE]['XCLASS']['ext/realty/pi1/class.tx_realty_frontEndEditor.php']);
}

?>
