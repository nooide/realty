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
 * Unit tests for the tx_realty_frontEndEditor class in the 'realty' extension.
 *
 * @package		TYPO3
 * @subpackage	tx_realty
 * @author		Saskia Metzler <saskia@merlin.owl.de>
 */

require_once(t3lib_extMgm::extPath('oelib').'class.tx_oelib_testingFramework.php');
require_once(t3lib_extMgm::extPath('oelib').'class.tx_oelib_headerProxyFactory.php');
require_once(t3lib_extMgm::extPath('oelib').'class.tx_oelib_mailerFactory.php');

require_once(t3lib_extMgm::extPath('realty').'lib/tx_realty_constants.php');
require_once(t3lib_extMgm::extPath('realty').'lib/class.tx_realty_object.php');
require_once(t3lib_extMgm::extPath('realty').'pi1/class.tx_realty_pi1.php');
require_once(t3lib_extMgm::extPath('realty').'pi1/class.tx_realty_frontEndEditor.php');

class tx_realty_frontEndEditor_testcase extends tx_phpunit_testcase {
	/** FE editor object to be tested */
	private $fixture;
	/** instance of tx_realty_pi1 */
	private $pi1;
	/** instance of tx_oelib_testingFramework */
	private $testingFramework;

	/** dummy FE user UID */
	private $feUserUid;
	/** UID of the dummy object */
	private $dummyObjectUid = 0;
	/** dummy string value */
	private static $dummyStringValue = 'test value';

	public function setUp() {
		// Bolsters up the fake front end.
		$GLOBALS['TSFE']->tmpl = t3lib_div::makeInstance('t3lib_tsparser_ext');
		$GLOBALS['TSFE']->tmpl->flattenSetup(array(), '', false);
		$GLOBALS['TSFE']->tmpl->init();
		$GLOBALS['TSFE']->tmpl->getCurrentPageData();
		$GLOBALS['TSFE']->initLLvars();

		tx_oelib_mailerFactory::getInstance()->enableTestMode();
		tx_oelib_headerProxyFactory::getInstance()->enableTestMode();
		$this->testingFramework = new tx_oelib_testingFramework('tx_realty');
		$this->createDummyRecords();

		$this->pi1 = new tx_realty_pi1();
		$this->pi1->init(
			array('templateFile' => 'EXT:realty/pi1/tx_realty_pi1.tpl.htm')
		);

		$this->fixture = new tx_realty_frontEndEditor($this->pi1, 0, '', true);
	}

	public function tearDown() {
		$this->testingFramework->logoutFrontEndUser();
		$this->testingFramework->cleanUp();
		tx_oelib_headerProxyFactory::getInstance()->getHeaderProxy()->purgeCollectedHeaders();
		tx_oelib_headerProxyFactory::getInstance()->disableTestMode();
		tx_oelib_mailerFactory::getInstance()->getMailer()->cleanUpCollectedEmailData();
		tx_oelib_mailerFactory::getInstance()->disableTestMode();

		unset($this->fixture, $this->pi1, $this->testingFramework);
	}


	///////////////////////
	// Utility functions.
	///////////////////////

	/**
	 * Creates dummy records in the DB.
	 */
	private function createDummyRecords() {
		$this->feUserUid = $this->testingFramework->createFrontEndUser(
			$this->testingFramework->createFrontEndUserGroup(),
			array(
				'username' => 'test_user',
				'name' => 'Mr. Test',
				'email' => 'mr-test@valid-email.org'
			)
		);
		$this->dummyObjectUid = $this->testingFramework->createRecord(
			REALTY_TABLE_OBJECTS,
			array(
				'object_number' => self::$dummyStringValue,
				'language' => self::$dummyStringValue
			)
		);
		$this->createAuxiliaryRecords();
	}

	/**
	 * Creates one dummy record in each table for auxiliary records.
	 */
	private function createAuxiliaryRecords() {
		$realtyObject = new tx_realty_object(true);
		$realtyObject->loadRealtyObject($this->dummyObjectUid);

		foreach (array(
			'city' => REALTY_TABLE_CITIES,
			'district' => REALTY_TABLE_DISTRICTS,
			'apartment_type' => REALTY_TABLE_APARTMENT_TYPES,
			'house_type' => REALTY_TABLE_HOUSE_TYPES,
			'heating_type' => REALTY_TABLE_HEATING_TYPES,
			'garage_type' => REALTY_TABLE_CAR_PLACES,
			'pets' => REALTY_TABLE_PETS,
			'state' => REALTY_TABLE_CONDITIONS
		) as $key => $table) {
			$realtyObject->setProperty($key, self::$dummyStringValue);
			$this->testingFramework->markTableAsDirty($table);
		}

		$realtyObject->writeToDatabase();
	}


	/////////////////////////////////////
	// Tests concerning deleteRecord().
	/////////////////////////////////////

	public function testDeleteRecordReturnsObjectDoesNotExistMessageForAnInvalidUidAndNoUserLoggedIn() {
		$this->fixture->setRealtyObjectUid($this->dummyObjectUid + 1);

		$this->assertContains(
			$this->pi1->translate('message_noResultsFound_fe_editor'),
			$this->fixture->deleteRecord()
		);
	}

	public function testDeleteRecordReturnsObjectDoesNotExistMessageForAnInvalidUidAndAUserLoggedIn() {
		$this->testingFramework->loginFrontEndUser($this->feUserUid);
		$this->fixture->setRealtyObjectUid($this->dummyObjectUid + 1);

		$this->assertContains(
			$this->pi1->translate('message_noResultsFound_fe_editor'),
			$this->fixture->deleteRecord()
		);
	}

	public function testHeaderIsSentWhenDeleteRecordReturnsObjectDoesNotExistMessage() {
		$this->fixture->setRealtyObjectUid($this->dummyObjectUid + 1);

		$this->assertContains(
			$this->pi1->translate('message_noResultsFound_fe_editor'),
			$this->fixture->deleteRecord()
		);
		$this->assertEquals(
			'Status: 404 Not Found',
			tx_oelib_headerProxyFactory::getInstance()->getHeaderProxy()->getLastAddedHeader()
		);
	}

	public function testDeleteRecordReturnsPleaseLoginMessageForANewObjectIfNoUserIsLoggedIn() {
		$this->fixture->setRealtyObjectUid(0);

		$this->assertContains(
			$this->pi1->translate('message_please_login'),
			$this->fixture->deleteRecord()
		);
	}

	public function testDeleteRecordReturnsPleaseLoginMessageForAnExistingObjectIfNoUserIsLoggedIn() {
		$this->fixture->setRealtyObjectUid($this->dummyObjectUid);

		$this->assertContains(
			$this->pi1->translate('message_please_login'),
			$this->fixture->deleteRecord()
		);
	}

	public function testDeleteRecordReturnsAccessDeniedMessageWhenLoggedInUserAttemptsToDeleteAnObjectHeDoesNotOwn() {
		$this->testingFramework->loginFrontEndUser(
			$this->testingFramework->createFrontEndUser(
				$this->testingFramework->createFrontEndUserGroup()
			)
		);
		$this->fixture->setRealtyObjectUid($this->dummyObjectUid);

		$this->assertContains(
			$this->pi1->translate('message_access_denied'),
			$this->fixture->deleteRecord()
		);
	}

	public function testHeaderIsSentWhenDeleteRecordReturnsAccessDeniedMessage() {
		$this->testingFramework->loginFrontEndUser(
			$this->testingFramework->createFrontEndUser(
				$this->testingFramework->createFrontEndUserGroup()
			)
		);
		$this->fixture->setRealtyObjectUid($this->dummyObjectUid);

		$this->assertContains(
			$this->pi1->translate('message_access_denied'),
			$this->fixture->deleteRecord()
		);
		$this->assertEquals(
			'Status: 403 Forbidden',
			tx_oelib_headerProxyFactory::getInstance()->getHeaderProxy()->getLastAddedHeader()
		);
	}

	public function testDeleteRecordReturnsAnEmptyStringWhenUserAuthorizedAndUidZero() {
		$this->testingFramework->loginFrontEndUser($this->feUserUid);
		$this->fixture->setRealtyObjectUid(0);

		$this->assertEquals(
			'',
			$this->fixture->deleteRecord()
		);
	}

	public function testDeleteRecordFromTheDatabase() {
		$this->testingFramework->changeRecord(
			REALTY_TABLE_OBJECTS,
			$this->dummyObjectUid,
			array('owner' => $this->feUserUid)
		);
		$this->testingFramework->loginFrontEndUser($this->feUserUid);
		$this->fixture->setRealtyObjectUid($this->dummyObjectUid);
		$this->fixture->deleteRecord();

		$this->assertEquals(
			0,
			$this->testingFramework->countRecords(
				REALTY_TABLE_OBJECTS,
				'uid='.$this->dummyObjectUid
					.$this->fixture->enableFields(REALTY_TABLE_OBJECTS)
			)
		);
	}

	public function testDeleteRecordReturnsAnEmptyStringWhenUserAuthorizedAndRecordWasDeleted() {
		$this->testingFramework->changeRecord(
			REALTY_TABLE_OBJECTS,
			$this->dummyObjectUid,
			array('owner' => $this->feUserUid)
		);
		$this->testingFramework->loginFrontEndUser($this->feUserUid);
		$this->fixture->setRealtyObjectUid($this->dummyObjectUid);

		$this->assertEquals(
			'',
			$this->fixture->deleteRecord()
		);
	}


	////////////////////////////////////////////////////
	// Tests for the functions called in the XML form.
	////////////////////////////////////////////////////
	// * Functions concerning the rendering.
	//////////////////////////////////////////

	public function testIsObjectNumberReadonlyReturnsFalseForANewObject() {
		$this->assertFalse(
			$this->fixture->isObjectNumberReadonly()
		);
	}

	public function testIsObjectNumberReadonlyReturnsTrueForAnExistingObject() {
		$this->fixture->setRealtyObjectUid($this->dummyObjectUid);

		$this->assertTrue(
			$this->fixture->isObjectNumberReadonly()
		);
	}

	public function testPopulateListOfCities() {
		$result = $this->fixture->populateListOfCities();
		$this->assertEquals(
			self::$dummyStringValue,
			$result[0]['caption']
		);
	}

	public function testPopulateListOfDistricts() {
		$result = $this->fixture->populateListOfDistricts();
		$this->assertEquals(
			self::$dummyStringValue,
			$result[0]['caption']
		);
	}

	public function testPopulateListOfApartmentTypes() {
		$result = $this->fixture->populateListOfApartmentTypes();
		$this->assertEquals(
			self::$dummyStringValue,
			$result[0]['caption']
		);
	}

	public function testPopulateListOfHouseTypes() {
		$result = $this->fixture->populateListOfHouseTypes();
		$this->assertEquals(
			self::$dummyStringValue,
			$result[0]['caption']
		);
	}

	public function testPopulateListOfHeatingTypes() {
		$result = $this->fixture->populateListOfHeatingTypes();
		$this->assertEquals(
			self::$dummyStringValue,
			$result[0]['caption']
		);
	}

	public function testPopulateListOfConditions() {
		$result = $this->fixture->populateListOfConditions();
		$this->assertEquals(
			self::$dummyStringValue,
			$result[0]['caption']
		);
	}

	public function testPopulateListOfCarPlaces() {
		$result = $this->fixture->populateListOfCarPlaces();
		$this->assertEquals(
			self::$dummyStringValue,
			$result[0]['caption']
		);
	}

	public function testPopulateListOfPets() {
		$result = $this->fixture->populateListOfPets();
		$this->assertEquals(
			self::$dummyStringValue,
			$result[0]['caption']
		);
	}


	//////////////////////////////////
	// * Message creation functions.
	//////////////////////////////////

	public function testGetNoValidNumberMessage() {
		$this->assertEquals(
			$GLOBALS['TSFE']->sL('LLL:EXT:realty/locallang_db.xml:tx_realty_objects.floor').': '
				.$this->pi1->translate('message_no_valid_number'),
			$this->fixture->getNoValidNumberMessage(array('fieldName' => 'floor'))
		);
	}

	public function testGetNoValidPriceMessage() {
		$this->assertEquals(
			$GLOBALS['TSFE']->sL('LLL:EXT:realty/locallang_db.xml:tx_realty_objects.floor').': '
				.$this->pi1->translate('message_no_valid_price'),
			$this->fixture->getNoValidPriceMessage(array('fieldName' => 'floor'))
		);
	}

	public function testGetValueNotAllowedMessage() {
		$this->assertEquals(
			$GLOBALS['TSFE']->sL('LLL:EXT:realty/locallang_db.xml:tx_realty_objects.object_type').': '
				.$this->pi1->translate('message_value_not_allowed'),
			$this->fixture->getValueNotAllowedMessage(array('fieldName' => 'object_type'))
		);
	}

	public function testGetRequiredFieldMessage() {
		$this->assertEquals(
			$GLOBALS['TSFE']->sL('LLL:EXT:realty/locallang_db.xml:tx_realty_objects.title').': '
				.$this->pi1->translate('message_required_field'),
			$this->fixture->getRequiredFieldMessage(array('fieldName' => 'title'))
		);
	}

	public function testGetNoValidYearMessage() {
		$this->assertEquals(
			$GLOBALS['TSFE']->sL('LLL:EXT:realty/locallang_db.xml:tx_realty_objects.construction_year').': '
				.$this->pi1->translate('message_no_valid_year'),
			$this->fixture->getNoValidYearMessage(array('fieldName' => 'construction_year'))
		);
	}

	public function testGetNoValidEmailMessage() {
		$this->assertEquals(
			$GLOBALS['TSFE']->sL('LLL:EXT:realty/locallang_db.xml:tx_realty_objects.contact_email').': '
				.$this->pi1->translate('label_set_valid_email_address'),
			$this->fixture->getNoValidEmailMessage()
		);
	}

	public function testGetNoValidPriceOrEmptyMessageForBuyingPriceFieldIfObjectToBuy() {
		$this->fixture->setFakedFormValue('object_type', '1');

		$this->assertEquals(
			$GLOBALS['TSFE']->sL('LLL:EXT:realty/locallang_db.xml:tx_realty_objects.buying_price').': '
				.$this->pi1->translate('message_enter_valid_non_empty_buying_price'),
			$this->fixture->getNoValidPriceOrEmptyMessage(array('fieldName' => 'buying_price'))
		);
	}

	public function testGetNoValidPriceOrEmptyMessageForBuyingPriceFieldIfObjectToRent() {
		$this->fixture->setFakedFormValue('object_type', '0');

		$this->assertEquals(
			$GLOBALS['TSFE']->sL('LLL:EXT:realty/locallang_db.xml:tx_realty_objects.buying_price').': '
				.$this->pi1->translate('message_enter_valid_or_empty_buying_price'),
			$this->fixture->getNoValidPriceOrEmptyMessage(array('fieldName' => 'buying_price'))
		);
	}

	public function testGetNoValidPriceOrEmptyMessageForRentFieldsIfObjectToRent() {
		$this->fixture->setFakedFormValue('object_type', '0');

		$this->assertEquals(
			$GLOBALS['TSFE']->sL('LLL:EXT:realty/locallang_db.xml:tx_realty_objects.rent_excluding_bills').': '
				.$this->pi1->translate('message_enter_valid_non_empty_rent'),
			$this->fixture->getNoValidPriceOrEmptyMessage(array('fieldName' => 'rent_excluding_bills'))
		);
	}

	public function testGetNoValidPriceOrEmptyMessageForRentFieldsIfObjectToBuy() {
		$this->fixture->setFakedFormValue('object_type', '1');

		$this->assertEquals(
			$GLOBALS['TSFE']->sL('LLL:EXT:realty/locallang_db.xml:tx_realty_objects.rent_excluding_bills').': '
				.$this->pi1->translate('message_enter_valid_or_empty_rent'),
			$this->fixture->getNoValidPriceOrEmptyMessage(array('fieldName' => 'rent_excluding_bills'))
		);
	}

	public function testGetInvalidObjectNumberMessageForEmptyObjectNumber() {
		$this->fixture->setFakedFormValue('object_number', '');

		$this->assertEquals(
			$GLOBALS['TSFE']->sL('LLL:EXT:realty/locallang_db.xml:tx_realty_objects.object_number').': '
				.$this->pi1->translate('message_required_field'),
			$this->fixture->getInvalidObjectNumberMessage()
		);
	}

	public function testGetInvalidObjectNumberMessageForNonEmptyObjectNumber() {
		$this->fixture->setFakedFormValue('object_number', 'foo');

		$this->assertEquals(
			$GLOBALS['TSFE']->sL('LLL:EXT:realty/locallang_db.xml:tx_realty_objects.object_number').': '
				.$this->pi1->translate('message_object_number_exists'),
			$this->fixture->getInvalidObjectNumberMessage()
		);
	}

	public function testGetMessageNotReturnsLocalizedFieldNameForInvalidFieldName() {
		$this->assertEquals(
			$this->pi1->translate('message_required_field'),
			$this->fixture->getRequiredFieldMessage(array('fieldName' => 'foo'))
		);
	}

	public function testGetEitherNewOrExistingRecordMessage() {
		$this->assertEquals(
			$GLOBALS['TSFE']->sL('LLL:EXT:realty/locallang_db.xml:tx_realty_objects.city').': '
				.$this->pi1->translate('message_either_new_or_existing_record'),
			$this->fixture->getEitherNewOrExistingRecordMessage(array('fieldName' => 'city'))
		);
	}

	public function testGetInvalidOrEmptyCityMessageForEmptyCity() {
		$this->fixture->setFakedFormValue('city', 0);

		$this->assertEquals(
			$GLOBALS['TSFE']->sL('LLL:EXT:realty/locallang_db.xml:tx_realty_objects.city').': '
				.$this->pi1->translate('message_required_field'),
			$this->fixture->getInvalidOrEmptyCityMessage()
		);
	}

	public function testGetInvalidOrEmptyCityMessageForNonEmptyCity() {
		$this->fixture->setFakedFormValue('city', $this->testingFramework->createRecord(REALTY_TABLE_CITIES) + 1);

		$this->assertEquals(
			$GLOBALS['TSFE']->sL('LLL:EXT:realty/locallang_db.xml:tx_realty_objects.city').': '
				.$this->pi1->translate('message_value_not_allowed'),
			$this->fixture->getInvalidOrEmptyCityMessage()
		);
	}


	////////////////////////////
	// * Validation functions.
	////////////////////////////

	public function testIsValidIntegerNumberReturnsTrueForAnIntegerInAString() {
		$this->assertTrue(
			$this->fixture->isValidIntegerNumber(array('value' => '12345'))
		);
	}

	public function testIsValidIntegerNumberReturnsTrueForAnIntegerWithSpaceAsThousandsSeparator() {
		$this->assertTrue(
			$this->fixture->isValidIntegerNumber(array('value' => '12 345'))
		);
	}

	public function testIsValidIntegerNumberReturnsTrueForAnEmptyString() {
		$this->assertTrue(
			$this->fixture->isValidIntegerNumber(array('value' => ''))
		);
	}

	public function testIsValidIntegerNumberReturnsFalseForANumberWithADotAsDecimalSeparator() {
		$this->assertFalse(
			$this->fixture->isValidIntegerNumber(array('value' => '123.45'))
		);
	}

	public function testIsValidIntegerNumberReturnsFalseForANumberWithACommaAsDecimalSeparator() {
		$this->assertFalse(
			$this->fixture->isValidIntegerNumber(array('value' => '123,45')	)
		);
	}

	public function testIsValidIntegerNumberReturnsFalseForANonNumericString() {
		$this->assertFalse(
			$this->fixture->isValidIntegerNumber(array('value' => 'string'))
		);
	}

	public function testIsValidNumberWithDecimalsReturnsTrueForANumberWithOneDecimal() {
		$this->assertTrue(
			$this->fixture->isValidNumberWithDecimals(array('value' => '1234.5'))
		);
	}

	public function testIsValidNumberWithDecimalsReturnsTrueForANumberWithOneDecimalAndASpace() {
		$this->assertTrue(
			$this->fixture->isValidNumberWithDecimals(array('value' => '1 234.5'))
		);
	}

	public function testIsValidNumberWithDecimalsReturnsTrueForANumberWithTwoDecimalsSeparatedByDot() {
		$this->assertTrue(
			$this->fixture->isValidNumberWithDecimals(array('value' => '123.45'))
		);
	}

	public function testIsValidNumberWithDecimalsReturnsTrueForANumberWithTwoDecimalsSeparatedByComma() {
		$this->assertTrue(
			$this->fixture->isValidNumberWithDecimals(array('value' => '123,45'))
		);
	}

	public function testIsValidNumberWithDecimalsReturnsTrueForANumberWithoutDecimals() {
		$this->assertTrue(
			$this->fixture->isValidNumberWithDecimals(array('value' => '12345'))
		);
	}

	public function testIsValidNumberWithDecimalsReturnsTrueForAnEmptyString() {
		$this->assertTrue(
			$this->fixture->isValidNumberWithDecimals(array('value' => ''))
		);
	}

	public function testIsValidNumberWithDecimalsReturnsFalseForANumberWithMoreThanTwoDecimals() {
		$this->assertFalse(
			$this->fixture->isValidNumberWithDecimals(array('value' => '12.345'))
		);
	}

	public function testIsValidNumberWithDecimalsReturnsFalseForANonNumericString() {
		$this->assertFalse(
			$this->fixture->isValidNumberWithDecimals(array('value' => 'string'))
		);
	}

	public function testIsValidYearReturnsTrueForTheCurrentYear() {
		$this->assertTrue(
			$this->fixture->isValidYear(array('value' => date('Y', mktime())))
		);
	}

	public function testIsValidYearReturnsTrueForAFormerYear() {
		$this->assertTrue(
			$this->fixture->isValidYear(array('value' => '2000'))
		);
	}

	public function testIsValidYearReturnsFalseForAFutureYear() {
		$this->assertFalse(
			$this->fixture->isValidYear(array('value' => '2100'))
		);
	}

	public function testIsNonEmptyValidPriceForObjectForSaleIfThePriceIsValid() {
		$this->fixture->setFakedFormValue('object_type', '1');
		$this->assertTrue(
			$this->fixture->isNonEmptyValidPriceForObjectForSale(
				array('value' => '1234')
			)
		);
	}

	public function testIsNonEmptyValidPriceForObjectForSaleIfThePriceIsInvalid() {
		$this->fixture->setFakedFormValue('object_type', '1');
		$this->assertFalse(
			$this->fixture->isNonEmptyValidPriceForObjectForSale(
				array('value' => 'foo')
			)
		);
	}

	public function testIsNonEmptyValidPriceForObjectForSaleIfThePriceIsEmpty() {
		$this->fixture->setFakedFormValue('object_type', '1');
		$this->assertFalse(
			$this->fixture->isNonEmptyValidPriceForObjectForSale(
				array('value' => '')
			)
		);
	}

	public function testIsNonEmptyValidPriceForObjectForRentIfOnePriceIsValidAndOneEmpty() {
		$this->fixture->setFakedFormValue('object_type', '0');
		$this->fixture->setFakedFormValue('year_rent', '');

		$this->assertTrue(
			$this->fixture->isNonEmptyValidPriceForObjectForRent(
				array('value' => '1234')
			)
		);
	}

	public function testIsNonEmptyValidPriceForObjectForRentIfTheOtherPriceIsValidAndOneEmpty() {
		$this->fixture->setFakedFormValue('object_type', '0');
		$this->fixture->setFakedFormValue('year_rent', '1234');

		$this->assertTrue(
			$this->fixture->isNonEmptyValidPriceForObjectForRent(
				array('value' => '')
			)
		);
	}

	public function testIsNonEmptyValidPriceForObjectForRentIfBothPricesAreValid() {
		$this->fixture->setFakedFormValue('object_type', '0');
		$this->fixture->setFakedFormValue('year_rent', '1234');

		$this->assertTrue(
			$this->fixture->isNonEmptyValidPriceForObjectForRent(
				array('value' => '1234')
			)
		);
	}

	public function testIsNonEmptyValidPriceForObjectForRentIfBothPricesAreInvalid() {
		$this->fixture->setFakedFormValue('object_type', '0');
		$this->fixture->setFakedFormValue('year_rent', 'foo');

		$this->assertFalse(
			$this->fixture->isNonEmptyValidPriceForObjectForRent(
				array('value' => 'foo')
			)
		);
	}

	public function testIsNonEmptyValidPriceForObjectForRentIfBothPricesAreEmpty() {
		$this->fixture->setFakedFormValue('object_type', '0');
		$this->fixture->setFakedFormValue('year_rent', '');

		$this->assertFalse(
			$this->fixture->isNonEmptyValidPriceForObjectForRent(
				array('value' => '')
			)
		);
	}

	public function testIsNonEmptyValidPriceForObjectForRentIfOnePriceIsInvalidAndOneValid() {
		$this->fixture->setFakedFormValue('object_type', '0');
		$this->fixture->setFakedFormValue('year_rent', '1234');

		$this->assertFalse(
			$this->fixture->isNonEmptyValidPriceForObjectForRent(
				array('value' => 'foo')
			)
		);
	}

	public function testIsNonEmptyValidPriceForObjectForRentIfTheOtherPriceIsInvalidAndOneValid() {
		$this->fixture->setFakedFormValue('object_type', '0');
		$this->fixture->setFakedFormValue('year_rent', 'foo');

		$this->assertFalse(
			$this->fixture->isNonEmptyValidPriceForObjectForRent(
				array('value' => '1234')
			)
		);
	}

	public function testIsObjectNumberUniqueForLanguageForUniqueCombination() {
		// The dummy record's language is not ''. A new record's language
		// is always ''.
		$this->assertTrue(
			$this->fixture->isObjectNumberUniqueForLanguage(
				array('value' => '1234')
			)
		);
	}

	public function testIsObjectNumberUniqueForLanguageForHiddenRecordWithDifferensObjectNumber() {
		$this->testingFramework->changeRecord(
			REALTY_TABLE_OBJECTS, $this->dummyObjectUid, array('hidden' => '1')
		);

		$this->assertTrue(
			$this->fixture->isObjectNumberUniqueForLanguage(
				array('value' => '1234')
			)
		);
	}

	public function testIsObjectNumberUniqueForLanguageForExistentCombination() {
		$this->testingFramework->changeRecord(
			REALTY_TABLE_OBJECTS, $this->dummyObjectUid, array('language' => '')
		);

		$this->assertFalse(
			$this->fixture->isObjectNumberUniqueForLanguage(
				array('value' => self::$dummyStringValue)
			)
		);
	}

	public function testIsObjectNumberUniqueForLanguageForHiddenRecordWithSameObjectNumber() {
		$this->testingFramework->changeRecord(
			REALTY_TABLE_OBJECTS,
			$this->dummyObjectUid,
			array('language' => '', 'hidden' => '1')
		);

		$this->assertFalse(
			$this->fixture->isObjectNumberUniqueForLanguage(
				array('value' => self::$dummyStringValue)
			)
		);
	}

	public function testIsObjectNumberUniqueForLanguageForEmptyObjectNumber() {
		$this->assertFalse(
			$this->fixture->isObjectNumberUniqueForLanguage(
				array('value' => '')
			)
		);
	}

	public function testIsAllowedValueForCityReturnsTrueForAllowedValue() {
		$this->assertTrue(
			$this->fixture->isAllowedValueForCity(
				array('value' => $this->testingFramework->createRecord(REALTY_TABLE_CITIES))
			)
		);
	}

	public function testIsAllowedValueForCityReturnsTrueForZeroIfANewRecordTitleIsProvided() {
		$this->fixture->setFakedFormValue('new_city', 'new city');

		$this->assertTrue(
			$this->fixture->isAllowedValueForCity(
				array('value' => '0')
			)
		);
	}

	public function testIsAllowedValueForCityReturnsFalseForZeroIfNoNewRecordTitleIsProvided() {
		$this->assertFalse(
			$this->fixture->isAllowedValueForCity(
				array('value' => '0')
			)
		);
	}

	public function testIsAllowedValueForCityReturnsFalseForInvalidValue() {
		$this->assertFalse(
			$this->fixture->isAllowedValueForCity(
				array('value' => $this->testingFramework->createRecord(REALTY_TABLE_CITIES) + 1)
			)
		);
	}

	public function testIsAllowedValueForDistrictReturnsTrueForAllowedValue() {
		$this->assertTrue(
			$this->fixture->isAllowedValueForDistrict(
				array('value' => $this->testingFramework->createRecord(REALTY_TABLE_DISTRICTS))
			)
		);
	}

	public function testIsAllowedValueForDistrictReturnsTrueForZero() {
		$this->assertTrue(
			$this->fixture->isAllowedValueForDistrict(
				array('value' => '0')
			)
		);
	}

	public function testIsAllowedValueForDistrictReturnsFalseForInvalidValue() {
		$this->assertFalse(
			$this->fixture->isAllowedValueForDistrict(
				array('value' => $this->testingFramework->createRecord(REALTY_TABLE_DISTRICTS) + 1)
			)
		);
	}

	public function testIsAllowedValueForHouseTypeReturnsTrueForAllowedValue() {
		$this->assertTrue(
			$this->fixture->isAllowedValueForHouseType(
				array('value' => $this->testingFramework->createRecord(REALTY_TABLE_HOUSE_TYPES))
			)
		);
	}

	public function testIsAllowedValueForHouseTypeReturnsTrueForZero() {
		$this->assertTrue(
			$this->fixture->isAllowedValueForHouseType(
				array('value' => '0')
			)
		);
	}

	public function testIsAllowedValueForHouseTypeReturnsFalseForInvalidValue() {
		$this->assertFalse(
			$this->fixture->isAllowedValueForHouseType(
				array('value' => $this->testingFramework->createRecord(REALTY_TABLE_HOUSE_TYPES) + 1)
			)
		);
	}

	public function testIsAllowedValueForApartmentTypeReturnsTrueForAllowedValue() {
		$this->assertTrue(
			$this->fixture->isAllowedValueForApartmentType(
				array('value' => $this->testingFramework->createRecord(REALTY_TABLE_APARTMENT_TYPES))
			)
		);
	}

	public function testIsAllowedValueForApartmentTypeReturnsTrueForZero() {
		$this->assertTrue(
			$this->fixture->isAllowedValueForApartmentType(
				array('value' => '0')
			)
		);
	}

	public function testIsAllowedValueForApartmentTypeReturnsFalseForInvalidValue() {
		$this->assertFalse(
			$this->fixture->isAllowedValueForApartmentType(
				array('value' => $this->testingFramework->createRecord(REALTY_TABLE_APARTMENT_TYPES) + 1)
			)
		);
	}

	public function testIsAllowedValueForHeatingTypeReturnsTrueForAllowedValue() {
		$this->assertTrue(
			$this->fixture->isAllowedValueForHeatingType(
				array('value' => $this->testingFramework->createRecord(REALTY_TABLE_HEATING_TYPES))
			)
		);
	}

	public function testIsAllowedValueForHeatingTypeReturnsTrueForZero() {
		$this->assertTrue(
			$this->fixture->isAllowedValueForHeatingType(
				array('value' => '0')
			)
		);
	}

	public function testIsAllowedValueForHeatingTypeReturnsFalseForInvalidValue() {
		$this->assertFalse(
			$this->fixture->isAllowedValueForHeatingType(
				array('value' => $this->testingFramework->createRecord(REALTY_TABLE_HEATING_TYPES) + 1)
			)
		);
	}

	public function testIsAllowedValueForGarageTypeReturnsTrueForAllowedValue() {
		$this->assertTrue(
			$this->fixture->isAllowedValueForGarageType(
				array('value' => $this->testingFramework->createRecord(REALTY_TABLE_CAR_PLACES))
			)
		);
	}

	public function testIsAllowedValueForGarageTypeReturnsTrueForZero() {
		$this->assertTrue(
			$this->fixture->isAllowedValueForGarageType(
				array('value' => '0')
			)
		);
	}

	public function testIsAllowedValueForGarageTypeReturnsFalseForInvalidValue() {
		$this->assertFalse(
			$this->fixture->isAllowedValueForGarageType(
				array('value' => $this->testingFramework->createRecord(REALTY_TABLE_CAR_PLACES) + 1)
			)
		);
	}

	public function testIsAllowedValueForStateReturnsTrueForAllowedValue() {
		$this->assertTrue(
			$this->fixture->isAllowedValueForState(
				array('value' => $this->testingFramework->createRecord(REALTY_TABLE_CONDITIONS))
			)
		);
	}

	public function testIsAllowedValueForStateReturnsTrueForZero() {
		$this->assertTrue(
			$this->fixture->isAllowedValueForState(
				array('value' => '0')
			)
		);
	}

	public function testIsAllowedValueForStateReturnsFalseForInvalidValue() {
		$this->assertFalse(
			$this->fixture->isAllowedValueForState(
				array('value' => $this->testingFramework->createRecord(REALTY_TABLE_CONDITIONS) + 1)
			)
		);
	}

	public function testIsAllowedValueForPetsReturnsTrueForAllowedValue() {
		$this->assertTrue(
			$this->fixture->isAllowedValueForPets(
				array('value' => $this->testingFramework->createRecord(REALTY_TABLE_PETS))
			)
		);
	}

	public function testIsAllowedValueForPetsReturnsTrueForZero() {
		$this->assertTrue(
			$this->fixture->isAllowedValueForPets(
				array('value' => '0')
			)
		);
	}

	public function testIsAllowedValueForPetsReturnsFalseForInvalidValue() {
		$this->assertFalse(
			$this->fixture->isAllowedValueForPets(
				array('value' => $this->testingFramework->createRecord(REALTY_TABLE_PETS) + 1)
			)
		);
	}

	public function testIsAtMostOneValueForCityRecordProvidedReturnsTrueForEmptyNewTitle() {
		$this->assertTrue(
			$this->fixture->isAtMostOneValueForCityRecordProvided(
				array('value' => '')
			)
		);
	}

	public function testIsAtMostOneValueForCityRecordProvidedReturnsTrueForNonEmptyNewTitleAndNoExistingRecord() {
		$this->fixture->setFakedFormValue('city', 0);

		$this->assertTrue(
			$this->fixture->isAtMostOneValueForCityRecordProvided(
				array('value' => $this->testingFramework->createRecord(REALTY_TABLE_CITIES))
			)
		);
	}

	public function testIsAtMostOneValueForCityRecordProvidedReturnsFalseForNonEmptyNewTitleAndExistingRecord() {
		$this->fixture->setFakedFormValue('city', $this->testingFramework->createRecord(REALTY_TABLE_CITIES));

		$this->assertFalse(
			$this->fixture->isAtMostOneValueForCityRecordProvided(
				array('value' => $this->testingFramework->createRecord(REALTY_TABLE_CITIES))
			)
		);
	}

	public function testIsAtMostOneValueForDistrictRecordProvidedReturnsTrueForEmptyNewTitle() {
		$this->assertTrue(
			$this->fixture->isAtMostOneValueForDistrictRecordProvided(
				array('value' => '')
			)
		);
	}

	public function testIsAtMostOneValueForDistrictRecordProvidedReturnsTrueForNonEmptyNewTitleAndNoExistingRecord() {
		$this->fixture->setFakedFormValue('district', 0);

		$this->assertTrue(
			$this->fixture->isAtMostOneValueForDistrictRecordProvided(
				array('value' => $this->testingFramework->createRecord(REALTY_TABLE_DISTRICTS))
			)
		);
	}

	public function testIsAtMostOneValueForDistrictRecordProvidedReturnsFalseForNonEmptyNewTitleAndExistingRecord() {
		$this->fixture->setFakedFormValue('district', $this->testingFramework->createRecord(REALTY_TABLE_DISTRICTS));

		$this->assertFalse(
			$this->fixture->isAtMostOneValueForDistrictRecordProvided(
				array('value' => $this->testingFramework->createRecord(REALTY_TABLE_DISTRICTS))
			)
		);
	}


	///////////////////////////////////////////////
	// * Functions called right before insertion.
	///////////////////////////////////////////////

	public function testAddAdministrativeDataAddsTheTimeStampForAnExistingObject() {
		$this->fixture->setRealtyObjectUid($this->dummyObjectUid);

		$result = $this->fixture->modifyDataToInsert(array());
		// object type will always be added and is not needed here.
		unset($result['object_type']);

		$this->assertEquals(
			'tstamp',
			key($result)
		);
	}

	public function testAddAdministrativeDataAddsTimeStampDatePidHiddenObjectTypeAndOwnerForANewObject() {
		$this->fixture->setRealtyObjectUid(0);

		$this->assertEquals(
			array('object_type', 'tstamp', 'pid', 'crdate', 'owner', 'hidden'),
			array_keys($this->fixture->modifyDataToInsert(array()))
		);
	}

	public function testAddAdministrativeDataAddsDefaultPidForANewObject() {
		$systemFolderPid = $this->testingFramework->createSystemFolder(1);
		$this->pi1->setConfigurationValue(
			'sysFolderForFeCreatedRecords', $systemFolderPid
		);
		$this->fixture->setRealtyObjectUid(0);
		$result = $this->fixture->modifyDataToInsert(array());

		$this->assertEquals(
			$systemFolderPid,
			$result['pid']
		);
	}

	public function testAddAdministrativeDataAddsPidDerivedFromCityRecordForANewObject() {
		$systemFolderPid = $this->testingFramework->createSystemFolder(1);
		$cityUid = $this->testingFramework->createRecord(
			REALTY_TABLE_CITIES, array('save_folder' => $systemFolderPid)
		);

		$this->fixture->setRealtyObjectUid(0);
		$result = $this->fixture->modifyDataToInsert(array('city' => $cityUid));

		$this->assertEquals(
			$systemFolderPid,
			$result['pid']
		);
	}

	public function testAddAdministrativeDataAddsPidDerivedFromCityRecordForAnExistentObject() {
		$systemFolderPid = $this->testingFramework->createSystemFolder(1);
		$cityUid = $this->testingFramework->createRecord(
			REALTY_TABLE_CITIES, array('save_folder' => $systemFolderPid)
		);
		$this->testingFramework->createRecord(
			REALTY_TABLE_OBJECTS, array('city' => $cityUid)
		);

		$this->fixture->setRealtyObjectUid(0);
		$result = $this->fixture->modifyDataToInsert(array('city' => $cityUid));

		$this->assertEquals(
			$systemFolderPid,
			$result['pid']
		);
	}

	public function testAddAdministrativeDataAddsFrontEndUserUidForANewObject() {
		$this->testingFramework->loginFrontEndUser($this->feUserUid);
		$this->fixture->setRealtyObjectUid(0);
		$result = $this->fixture->modifyDataToInsert(array());

		$this->assertEquals(
			$this->feUserUid,
			$result['owner']
		);
	}

	public function testAddAdministrativeNotDataAddsFrontEndUserUidForAnObjectToUpdate() {
		$this->fixture->setRealtyObjectUid($this->dummyObjectUid);
		$result = $this->fixture->modifyDataToInsert(array());

		$this->assertFalse(
			isset($result['owner'])
		);
	}

	public function testNewRecordIsMarkedAsHidden() {
		$this->fixture->setRealtyObjectUid(0);
		$result = $this->fixture->modifyDataToInsert(array());

		$this->assertEquals(
			1,
			$result['hidden']
		);
	}

	public function testExistingRecordIsNotMarkedAsHidden() {
		$this->fixture->setRealtyObjectUid($this->dummyObjectUid);
		$result = $this->fixture->modifyDataToInsert(array());

		$this->assertFalse(
			isset($result['hidden'])
		);
	}

	public function testUnifyNumbersToInsertForNoElementsWithNumericValues() {
		$this->fixture->setRealtyObjectUid($this->dummyObjectUid);
		$formData = array('foo' => '12,3.45', 'bar' => 'abc,de.fgh');
		$result = $this->fixture->modifyDataToInsert($formData);
		// PID, object type and time stamp will always be added,
		// they are not needed here.
		unset($result['tstamp'], $result['pid'], $result['object_type']);

		$this->assertEquals(
			$formData,
			$result
		);
	}

	public function testUnifyNumbersToInsertIfSomeElementsNeedFormatting() {
		$this->fixture->setRealtyObjectUid($this->dummyObjectUid);
		$result = $this->fixture->modifyDataToInsert(array(
			'garage_rent' => '123,45',
			'garage_price' => '12 345'
		));
		// PID, object type and time stamp will always be added,
		// they are not needed here.
		unset($result['tstamp'], $result['pid'], $result['object_type']);

		$this->assertEquals(
			array('garage_rent' => '123.45', 'garage_price' => '12345'),
			$result
		);
	}

	public function testStoreNewAuxiliaryRecordsDeletesNonEmptyNewCityElement() {
		$this->fixture->setRealtyObjectUid($this->dummyObjectUid);
		$result = $this->fixture->modifyDataToInsert(
			array('new_city' => 'foo',)
		);

		$this->assertFalse(
			isset($result['new_city'])
		);
	}

	public function testStoreNewAuxiliaryRecordsDeletesEmptyNewCityElement() {
		$this->fixture->setRealtyObjectUid($this->dummyObjectUid);
		$result = $this->fixture->modifyDataToInsert(
			array('new_city' => '')
		);

		$this->assertFalse(
			isset($result['new_city'])
		);
	}

	public function testStoreNewAuxiliaryRecordsDeletesNonEmptyNewDistrictElement() {
		$this->fixture->setRealtyObjectUid($this->dummyObjectUid);
		$result = $this->fixture->modifyDataToInsert(
			array('new_district' => 'foo',)
		);

		$this->assertFalse(
			isset($result['new_district'])
		);
	}

	public function testStoreNewAuxiliaryRecordsDeletesEmptyNewDistrictElement() {
		$this->fixture->setRealtyObjectUid($this->dummyObjectUid);
		$result = $this->fixture->modifyDataToInsert(
			array('new_district' => '')
		);

		$this->assertFalse(
			isset($result['new_district'])
		);
	}

	public function testStoreNewAuxiliaryRecordsNotCreatesANewRecordForAnExistingTitle() {
		$this->fixture->setRealtyObjectUid($this->dummyObjectUid);
		$this->fixture->modifyDataToInsert(
			array('new_city' => self::$dummyStringValue)
		);

		$this->assertEquals(
			1,
			$this->testingFramework->countRecords(
				REALTY_TABLE_CITIES,
				'title="'.self::$dummyStringValue.'" AND is_dummy_record=1'
			)
		);
	}

	public function testStoreNewAuxiliaryRecordsCreatesANewRecordForANewTitle() {
		$this->fixture->setRealtyObjectUid($this->dummyObjectUid);
		$this->fixture->modifyDataToInsert(array('new_city' => 'new city'));

		$this->assertEquals(
			1,
			$this->testingFramework->countRecords(
				REALTY_TABLE_CITIES, 'title="new city" AND is_dummy_record=1'
			)
		);
	}

	public function testStoreNewAuxiliaryRecordsCreatesANewRecordWithCorrectPid() {
		$pid = $this->testingFramework->createSystemFolder(1);
		$this->pi1->setConfigurationValue(
			'sysFolderForFeCreatedAuxiliaryRecords', $pid
		);
		$this->fixture->setRealtyObjectUid($this->dummyObjectUid);
		$this->fixture->modifyDataToInsert(array('new_city' => 'new city'));

		$this->assertEquals(
			1,
			$this->testingFramework->countRecords(
				REALTY_TABLE_CITIES,
				'title="new city" AND pid='.$pid.' AND is_dummy_record=1'
			)
		);
	}

	public function testStoreNewAuxiliaryRecordsStoresNewUidToTheFormData() {
		$this->fixture->setRealtyObjectUid($this->dummyObjectUid);
		$result = $this->fixture->modifyDataToInsert(
			array('new_city' => 'new city')
		);

		$this->assertTrue(
			isset($result['city'])
		);
		$this->assertFalse(
			$result['city'] == 0
		);
	}

	public function testStoreNewAuxiliaryRecordsCreatesnoNewRecordForAnEmptyTitle() {
		$this->fixture->setRealtyObjectUid($this->dummyObjectUid);
		$this->fixture->modifyDataToInsert(array('new_city' => ''));

		$this->assertEquals(
			1,
			$this->testingFramework->countRecords(
				REALTY_TABLE_CITIES, 'is_dummy_record=1'
			)
		);
	}

	public function testStoreNewAuxiliaryRecordsNotCreatesARecordIfAUidIsAlreadySet() {
		$this->fixture->setRealtyObjectUid($this->dummyObjectUid);
		$result = $this->fixture->modifyDataToInsert(
			array('city' => 1, 'new_city' => 'new city')
		);

		$this->assertEquals(
			0,
			$this->testingFramework->countRecords(
				REALTY_TABLE_CITIES, 'title="new city" AND is_dummy_record=1'
			)
		);
		$this->assertTrue(
			$result['city'] == 1
		);
	}


	////////////////////////////////////////
	// * Functions called after insertion.
	/////////////////////////////////////////////////////
	// ** sendEmailForNewObjectAndClearFrontEndCache().
	/////////////////////////////////////////////////////

	public function testSendEmailForNewObjectSendsToTheConfiguredRecipient() {
		// This will create an empty dummy record.
		$this->fixture->writeFakedFormDataToDatabase();
		$this->pi1->setConfigurationValue(
			'feEditorNotifyEmail', 'recipient@valid-email.org'
		);
		$this->testingFramework->loginFrontEndUser($this->feUserUid);
		$this->fixture->sendEmailForNewObjectAndClearFrontEndCache();
		$this->assertEquals(
			'recipient@valid-email.org',
			tx_oelib_mailerFactory::getInstance()->getMailer()->getLastRecipient()
		);
	}

	public function testSentEmailHasTheCurrentFeUserAsFrom() {
		// This will create an empty dummy record.
		$this->fixture->writeFakedFormDataToDatabase();
		$this->pi1->setConfigurationValue(
			'feEditorNotifyEmail', 'recipient@valid-email.org'
		);
		$this->testingFramework->loginFrontEndUser($this->feUserUid);
		$this->fixture->sendEmailForNewObjectAndClearFrontEndCache();

		$this->assertEquals(
			'From: "Mr. Test" <mr-test@valid-email.org>'.LF,
			tx_oelib_mailerFactory::getInstance()->getMailer()->getLastHeaders()
		);
	}

	public function testSentEmailContainsTheFeUsersName() {
		// This will create an empty dummy record.
		$this->fixture->writeFakedFormDataToDatabase();
		$this->pi1->setConfigurationValue(
			'feEditorNotifyEmail', 'recipient@valid-email.org'
		);
		$this->testingFramework->loginFrontEndUser($this->feUserUid);
		$this->fixture->sendEmailForNewObjectAndClearFrontEndCache();

		$this->assertContains(
			'Mr. Test',
			tx_oelib_mailerFactory::getInstance()->getMailer()->getLastBody()
		);
	}

	public function testSentEmailContainsTheFeUsersUsername() {
		// This will create an empty dummy record.
		$this->fixture->writeFakedFormDataToDatabase();
		$this->pi1->setConfigurationValue(
			'feEditorNotifyEmail', 'recipient@valid-email.org'
		);
		$this->testingFramework->loginFrontEndUser($this->feUserUid);
		$this->fixture->sendEmailForNewObjectAndClearFrontEndCache();

		$this->assertContains(
			'test_user',
			tx_oelib_mailerFactory::getInstance()->getMailer()->getLastBody()
		);
	}

	public function testSentEmailContainsTheNewObjectsTitle() {
		$this->fixture->setFakedFormValue('title', 'any title');
		$this->fixture->writeFakedFormDataToDatabase();
		$this->pi1->setConfigurationValue(
			'feEditorNotifyEmail', 'recipient@valid-email.org'
		);
		$this->testingFramework->loginFrontEndUser($this->feUserUid);
		$this->fixture->sendEmailForNewObjectAndClearFrontEndCache();

		$this->assertContains(
			'any title',
			tx_oelib_mailerFactory::getInstance()->getMailer()->getLastBody()
		);
	}

	public function testSentEmailContainsTheNewObjectsObjectNumber() {
		$this->fixture->setFakedFormValue('object_number', '1234');
		$this->fixture->writeFakedFormDataToDatabase();
		$this->pi1->setConfigurationValue(
			'feEditorNotifyEmail', 'recipient@valid-email.org'
		);
		$this->testingFramework->loginFrontEndUser($this->feUserUid);
		$this->fixture->sendEmailForNewObjectAndClearFrontEndCache();

		$this->assertContains(
			'1234',
			tx_oelib_mailerFactory::getInstance()->getMailer()->getLastBody()
		);
	}

	public function testSentEmailContainsTheNewObjectsUid() {
		// The UID is found with the help of the combination of object number
		// and language.
		$this->fixture->setFakedFormValue('object_number', '1234');
		$this->fixture->setFakedFormValue('language', 'XY');
		$this->testingFramework->loginFrontEndUser($this->feUserUid);
		$this->fixture->writeFakedFormDataToDatabase();
		$this->pi1->setConfigurationValue(
			'feEditorNotifyEmail', 'recipient@valid-email.org'
		);
		$this->fixture->sendEmailForNewObjectAndClearFrontEndCache();

		$expectedResult = $this->testingFramework->getAssociativeDatabaseResult(
			$GLOBALS['TYPO3_DB']->exec_SELECTquery(
				'uid',
				REALTY_TABLE_OBJECTS,
				'object_number="1234" AND language="XY"'
			)
		);

		$this->assertContains(
			(string) $expectedResult['uid'],
			tx_oelib_mailerFactory::getInstance()->getMailer()->getLastBody()
		);
	}

	public function testNoEmailIsSentIfNoRecipientWasConfigured() {
		$this->pi1->setConfigurationValue('feEditorNotifyEmail', '');
		$this->testingFramework->loginFrontEndUser($this->feUserUid);
		$this->fixture->sendEmailForNewObjectAndClearFrontEndCache();

		$this->assertEquals(
			array(),
			tx_oelib_mailerFactory::getInstance()->getMailer()->getLastEmail()
		);
	}

	public function testNoEmailIsSentForExistingObject() {
		$this->fixture->setRealtyObjectUid($this->dummyObjectUid);
		$this->pi1->setConfigurationValue(
			'feEditorNotifyEmail', 'recipient@valid-email.org'
		);
		$this->testingFramework->loginFrontEndUser($this->feUserUid);
		$this->fixture->sendEmailForNewObjectAndClearFrontEndCache();

		$this->assertEquals(
			array(),
			tx_oelib_mailerFactory::getInstance()->getMailer()->getLastEmail()
		);
	}

	public function testClearFrontEndCacheDeletesCachedPage() {
		$pageUid = $this->testingFramework->createFrontEndPage();
		$contentUid = $this->testingFramework->createContentElement(
			$pageUid,
			array('list_type' => 'tx_realty_pi1')
		);
		$this->testingFramework->createPageCacheEntry($contentUid);

		$this->fixture->sendEmailForNewObjectAndClearFrontEndCache();

		$this->assertEquals(
			0,
			$this->testingFramework->countRecords(
				'cache_pages',
				'page_id='.$pageUid
			)
		);
	}
}

?>