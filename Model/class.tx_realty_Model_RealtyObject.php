<?php
/***************************************************************
* Copyright notice
*
* (c) 2007-2013 Saskia Metzler <saskia@merlin.owl.de>
* All rights reserved
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

require_once(t3lib_extMgm::extPath('realty') . 'lib/tx_realty_constants.php');

/**
 * This class represents a realty object.
 *
 * @package TYPO3
 * @subpackage tx_realty
 *
 * @author Saskia Metzler <saskia@merlin.owl.de>
 * @author Oliver Klee <typo3-coding@oliverklee.de>
 * @author Bernd Schönbach <bernd@oliverklee.de>
 */
class tx_realty_Model_RealtyObject extends tx_oelib_Model implements tx_oelib_Interface_Geo {
	/**
	 * status code meaning "vacant"
	 *
	 * @var integer
	 */
	const STATUS_VACANT = 0;

	/**
	 * status code meaning "reserved"
	 *
	 * @var integer
	 */
	const STATUS_RESERVED = 1;

	/**
	 * status code meaning "sold"
	 *
	 * @var integer
	 */
	const STATUS_SOLD = 2;

	/**
	 * status code meaning "rented"
	 *
	 * @var integer
	 */
	const STATUS_RENTED = 3;

	/**
	 * @var string the charset that is used for the output
	 */
	private $renderCharset = 'utf-8';

	/**
	 * @var t3lib_cs helper for charset conversion
	 */
	private $charsetConversion = NULL;

	/**
	 * @var integer the length of cropped titles
	 */
	const CROP_SIZE = 32;

	/**
	 * the images related to this realty object
	 *
	 * @var tx_oelib_List<tx_realty_Model_Image>
	 */
	private $images = NULL;

	/**
	 * whether the image records need to get saved
	 *
	 * @var boolean
	 */
	private $imagesNeedToGetSaved = FALSE;

	/**
	 * whether the old image records associated with this model need to get deleted
	 *
	 * @var boolean
	 */
	private $oldImagesNeedToGetDeleted = FALSE;

	/**
	 * the documents related to this realty object
	 *
	 * @var tx_oelib_List<tx_realty_Model_Document>
	 */
	private $documents = NULL;

	/**
	 * whether the related documents need to get saved
	 *
	 * @var boolean
	 */
	private $documentsNeedToGetSaved = FALSE;

	/**
	 * whether the old document records associated with this model need to get
	 * deleted
	 *
	 * @var boolean
	 */
	private $oldDocumentsNeedToGetDeleted = FALSE;

	/**
	 * @var array the owner record is cached in order to improve performance
	 */
	private $ownerData = array();

	/**
	 * @var array required fields for OpenImmo records
	 */
	private $requiredFields = array(
		'zip',
		'object_number',
		// 'object_type' refers to 'vermarktungsart' in the OpenImmo schema.
		'object_type',
		'house_type',
		'employer',
		'openimmo_anid',
		'openimmo_obid',
		'contact_person',
		'contact_email'
	);

	/**
	 * @var array associates property names and their corresponding tables
	 */
	private static $propertyTables = array(
		REALTY_TABLE_CITIES => 'city',
		REALTY_TABLE_APARTMENT_TYPES => 'apartment_type',
		REALTY_TABLE_HOUSE_TYPES => 'house_type',
		REALTY_TABLE_DISTRICTS => 'district',
		REALTY_TABLE_PETS => 'pets',
		REALTY_TABLE_CAR_PLACES => 'garage_type',
	);

	/**
	 * @var boolean whether hidden objects are loadable
	 */
	private $canLoadHiddenObjects = TRUE;

	/**
	 * @var boolean whether a newly created record is for testing purposes only
	 */
	private $isDummyRecord = FALSE;

	/**
	 * @var t3lib_refindex a cached reference index instance
	 */
	private static $referenceIndex = NULL;

	/**
	 * @var tx_realty_Model_FrontEndUser the owner of this object
	 */
	private $owner = NULL;

	/**
	 * Constructor.
	 *
	 * @param boolean $createDummyRecords whether the database records to create are for testing purposes only
	 */
	public function __construct($createDummyRecords = FALSE) {
		$this->isDummyRecord = $createDummyRecords;

		$this->initializeCharsetConversion();

		$this->images = new tx_oelib_List();
		$this->documents = new tx_oelib_List();
	}

	/**
	 * Destructor.
	 */
	public function __destruct() {
		unset($this->charsetConversion, $this->owner, $this->images);

		parent::__destruct();
	}

	/**
	 * Gets allowed image file extensions.
	 *
	 * @return string[] lowercased allowed image file extensions, might be empty
	 */
	protected function getAllowedImageExtensions() {
		$allowedImageExtensions = t3lib_div::trimExplode(',', strtolower($GLOBALS['TYPO3_CONF_VARS']['GFX']['imagefile_ext']), TRUE);

		return array_filter($allowedImageExtensions, array($this, 'isNotPdfOrPs'));
	}

	/**
	 * Callback function for array_filter to remove PDF and PS from the extension list.
	 *
	 * @param string $fileExtension the extension to check
	 *
	 * @return boolean TRUE if the $fileExtension is not "pdf" or "ps", FALSE otherwise
	 */
	protected function isNotPdfOrPs($fileExtension) {
		return !in_array($fileExtension, array('pdf', 'ps'));
	}

	/**
	 * Checks if the file extension $imageExtension is of an allowed image file type.
	 *
	 * @param string $imageExtension the file extension is to be checked.
	 *
	 * @return boolean
	 *         TRUE if the file extension $imageExtension is included in the list of allowed image extensions,
	 *         FALSE otherwise.
	 */
	protected function isValidImageExtension($imageExtension) {
		return in_array(strtolower($imageExtension), $this->getAllowedImageExtensions());
	}

	/**
	 * Receives the data for a new realty object to load.
	 *
	 * The received data can either be a database result row or an array which
	 * has database column names as keys (may be empty). The data can also be a
	 * UID of an existent realty object to load from the database. If the data
	 * is of an invalid type, an empty array will be set.
	 *
	 * @param mixed $realtyData
	 *        data for the realty object: an array or a UID (of integer > 0) of
	 *        an existing record, an array must not contain the key 'uid'
	 * @param boolean $canLoadHiddenObjects whether hidden objects are loadable
	 *
	 * @deprecated 2009-02-03 use setData() instead
	 *
	 * @return void
	 */
	public function loadRealtyObject($realtyData, $canLoadHiddenObjects = FALSE) {
		$this->canLoadHiddenObjects = $canLoadHiddenObjects;

		switch ($this->getDataType($realtyData)) {
			case 'array' :
				$this->setData($realtyData);
				break;
			case 'uid' :
				$this->setData($this->loadDatabaseEntry(intval($realtyData)));
				break;
			default :
				$this->setData(array());
				break;
		}
	}

	/**
	 * Receives the data for a new realty object to load.
	 *
	 * The received data can either be a database result row or an array which
	 * has database column names as keys (may be empty). The data can also be a
	 * UID of an existent realty object to load from the database. If the data
	 * is of an invalid type, an empty array will be set.
	 *
	 * @param array $realtyData data for the realty object
	 *
	 * @return void
	 */
	public function setData(array $realtyData) {
		if (is_array($realtyData['images']) || is_array($realtyData['documents'])) {
			$dataWithImages = $this->isolateImageRecords($realtyData);
			$dataWithImagesAndDocuments = $this->isolateDocumentRecords(
				$dataWithImages
			);
			parent::setData($dataWithImagesAndDocuments);
		} else {
			parent::setData($realtyData);
			$this->retrieveAttachedImages();
			$this->retrieveAttachedDocuments();
		}
	}

	/**
	 * Sets the test mode. If this mode is enabled, all data written to the
	 * database will receive the dummy record flag.
	 *
	 * @return void
	 */
	public function setTestMode() {
		$this->isDummyRecord = TRUE;
	}

	/**
	 * Checks the type of data input. Returns the type if it is valid, else
	 * returns an empty string.
	 *
	 * @param mixed $realtyData
	 *        data for the realty object: an array or a UID (of integer > 0)
	 *
	 * @return string
	 *         type of data: 'array', 'uid' or empty in case case of any other
	 *         type
	 */
	protected function getDataType($realtyData) {
		if ($realtyData === NULL) {
			return '';
		}

		$result = '';

		if (is_array($realtyData)) {
			$result = 'array';
		} elseif (is_numeric($realtyData)) {
			$result = 'uid';
		}

		return $result;
	}

	/**
	 * Loads an existing realty object entry from the database. If
	 * $enabledObjectsOnly is set, deleted or hidden records will not be loaded.
	 *
	 * @param integer $uid UID of the database entry to load, must be > 0
	 *
	 * @return array contents of the database entry, empty if database
	 *               result could not be fetched
	 */
	protected function loadDatabaseEntry($uid) {
		try {
			$result = tx_oelib_db::selectSingle(
				'*',
				REALTY_TABLE_OBJECTS,
				'uid=' . $uid . tx_oelib_db::enableFields(
					REALTY_TABLE_OBJECTS, $this->canLoadHiddenObjects ? 1 : -1
				)
			);
		} catch (tx_oelib_Exception_EmptyQueryResult $exception) {
			$result = array();
		}

		return $result;
	}

	/**
	 * Stores the image records to $this->images and writes the number of images
	 * to the imported data array instead as this number is expected by the
	 * database configuration.
	 *
	 * @param array $data
	 *        realty record to be loaded as a realty object, may be empty
	 *
	 * @return array
	 *         realty record ready to load, image records got separated, will be
	 *         empty if the given array was empty
	 */
	private function isolateImageRecords(array $data) {
		if (!is_array($data['images'])) {
			return $data;
		}

		$result = $data;
		$result['images'] = count($data['images']);
		$this->images = t3lib_div::makeInstance('tx_oelib_List');

		foreach ($data['images'] as $imageData) {
			/** @var $image tx_realty_Model_Image */
			$image = t3lib_div::makeInstance('tx_realty_Model_Image');
			$image->setTitle($imageData['caption']);
			$image->setFileName($imageData['image']);
			$image->setPageUid(intval($imageData['pid']));
			$image->setSorting(intval($imageData['sorting']));
			$image->setPosition(intval($imageData['position']));

			$this->images->add($image);
		}

		$this->oldImagesNeedToGetDeleted = TRUE;
		$this->imagesNeedToGetSaved = TRUE;

		return $result;
	}

	/**
	 * Stores the document records to $this->documents and writes the number of
	 * documents to the imported data array instead as this number is expected
	 * by the database configuration.
	 *
	 * @param array $data
	 *        realty record to be loaded as a realty object, may be empty
	 *
	 * @return array
	 *         realty record ready to load, document records got separated, will
	 *         be empty if the given array was empty
	 */
	private function isolateDocumentRecords(array $data) {
		if (!is_array($data['documents'])) {
			return $data;
		}

		$result = $data;
		$result['documents'] = count($data['documents']);
		$this->documents = t3lib_div::makeInstance('tx_oelib_List');

		foreach ($data['documents'] as $documentData) {
			/** @var $document tx_realty_Model_Document */
			$document = t3lib_div::makeInstance('tx_realty_Model_Document');
			$document->setTitle($documentData['title']);
			$document->setFileName($documentData['filename']);
			$document->setPageUid(intval($documentData['pid']));
			$document->setSorting(intval($documentData['sorting']));

			$this->documents->add($document);
		}

		$this->oldDocumentsNeedToGetDeleted = TRUE;
		$this->documentsNeedToGetSaved = TRUE;

		return $result;
	}

	/**
	 * Writes a realty object to the database. Deletes keys which do not exist
	 * in the database and inserts certain values to separate tables, as
	 * associated in self::$propertyTables.
	 * A new record will only be inserted if all required fields occur as keys
	 * in the realty object data to insert.
	 *
	 * @param integer $overridePid PID for new records (omit this parameter to use the PID set in the global configuration)
	 * @param boolean $setOwner TRUE if the owner may be set, FALSE otherwise
	 *
	 * @return string locallang key of an error message if the record was
	 *                not written to database, an empty string if it was
	 *                written successfully
	 */
	public function writeToDatabase($overridePid = 0, $setOwner = FALSE) {
		// If contact_email is the only field, the object is assumed to be not
		// loaded.
		if ($this->isEmpty()
			|| ($this->existsKey('contact_email')
			&& (count($this->getAllProperties()) == 1))
		) {
			return 'message_object_not_loaded';
		}

		if (count($this->checkForRequiredFields()) > 0) {
			return 'message_fields_required';
		}

		$errorMessage = '';
		$this->prepareInsertionAndInsertRelations();
		if ($setOwner) {
			$this->processOwnerData();
		}

		$ownerCanAddObjects = $this->ownerMayAddObjects();

		if ($this->identifyObjectAndSetUid()) {
			if (!$this->getAsBoolean('deleted')) {
				$this->updateDatabaseEntry($this->getAllProperties());
			} else {
				$this->discardExistingImages();
				$this->discardExistingDocuments();
				tx_oelib_MapperRegistry::get('tx_realty_Mapper_RealtyObject')
					->delete($this);
				$errorMessage = 'message_deleted_flag_causes_deletion';
			}
		} elseif (!$ownerCanAddObjects) {
			$errorMessage = 'message_object_limit_reached';
		} else {
			$newUid = $this->createNewDatabaseEntry(
				$this->getAllProperties(), REALTY_TABLE_OBJECTS, $overridePid
			);
			switch ($newUid) {
				case -1:
					$errorMessage = 'message_deleted_flag_set';
					break;
				case 0:
					$errorMessage = 'message_insertion_failed';
				default:
					$this->setUid($newUid);
					break;
			}
		}

		if (($errorMessage == '')
			|| ($errorMessage == 'message_deleted_flag_causes_deletion')
		) {
			$this->refreshImageEntries($overridePid);
			$this->refreshDocumentEntries($overridePid);
		}

		return $errorMessage;
	}

	/**
	 * Checks whether an owner may add objects to the database.
	 *
	 * @return boolean TRUE if the current owner may add objects to the database
	 */
	private function ownerMayAddObjects() {
		if ($this->isOwnerDataUsable()) {
			$this->getOwner()->resetObjectsHaveBeenCalculated();
			$ownerCanAddObjects = $this->getOwner()->canAddNewObjects();
		} else {
			$ownerCanAddObjects = TRUE;
		}

		return $ownerCanAddObjects;
	}

	/**
	 * Loads the owner's database record into $this->ownerData.
	 *
	 * @return void
	 */
	private function loadOwnerRecord() {
		if (!$this->hasOwner() && ($this->getAsString('openimmo_anid') == '')) {
			return;
		}

		if ($this->hasOwner()) {
			$whereClause = 'uid=' . $this->getAsInteger('owner');
		} else {
			$whereClause = 'tx_realty_openimmo_anid="' .
				$GLOBALS['TYPO3_DB']->quoteStr(
					$this->getAsString('openimmo_anid'), REALTY_TABLE_OBJECTS
				) . '" ';
		}

		try {
			$row = tx_oelib_db::selectSingle(
				'*',
				'fe_users',
				$whereClause . tx_oelib_db::enableFields('fe_users')
			);
			$this->ownerData = $row;
		} catch (tx_oelib_Exception_EmptyQueryResult $exception) {
		}
	}

	/**
	 * Links the current realty object to the owner's FE user record if there is
	 * one. The owner is identified by their OpenImmo ANID.
	 * Sets whether to use the FE user's contact data or the data provided
	 * within the realty record, according to the configuration.
	 *
	 * @return void
	 */
	private function processOwnerData() {
		$this->addRealtyRecordsOwner();
		if ($this->isOwnerDataUsable()) {
			$this->setProperty('contact_data_source', 1);
		}
	}

	/**
	 * Adds the current realty record's owner. The owner is the FE user who has
	 * the same OpenImmo ANID as provided in the current realty record.
	 *
	 * If the current record already has an owner or if no matching ANID was
	 * found, no owner will be set.
	 *
	 * @return void
	 */
	private function addRealtyRecordsOwner() {
		// Saves an existing owner from being overwritten.
		if ($this->hasOwner()) {
			return;
		}

		try {
			$this->setAsInteger('owner', $this->getOwner()->getUid());
		} catch (tx_oelib_Exception_NotFound $exception) {
		}
	}

	/**
	 * Returns whether the owner's data may be used in the FE according to the
	 * current configuration.
	 *
	 * @return boolean TRUE if there is an owner and his data may be used in
	 *                 the FE, FALSE otherwise
	 */
	private function isOwnerDataUsable() {
		return (tx_oelib_configurationProxy::getInstance('realty')
			->getAsBoolean('useFrontEndUserDataAsContactDataForImportedRecords')
				&& $this->hasOwner()
		);
	}

	/**
	 * Returns the owner as model. This will usually be the FE user who has a
	 * relation to the object. If there is none, the FE user with an ANID
	 * that matches the object's ANID will be returned.
	 *
	 * TODO: When saving relations works with models (Bug 2680, 2681), this
	 *       function should return a real relation. $this->ownerData is no
	 *       longer needed then either.
	 *
	 * @throws tx_oelib_Exception_NotFound if there is no owner - not even a FE
	 *                                     user with an ANID matching the
	 *                                     current object's ANID
	 *
	 * @return tx_realty_Model_FrontEndUser owner of the current object, NULL
	 *                                      if there is none
	 */
	public function getOwner() {
		if (empty($this->ownerData)
			|| ($this->ownerData['uid'] != $this->getAsInteger('owner'))
			|| ($this->ownerData['tx_realty_openimmo_anid']
					!= $this->getAsString('openimmo_anid')
				)
		) {
			$this->loadOwnerRecord();
		}

		try {
			$result = tx_oelib_MapperRegistry::get('tx_realty_Mapper_FrontEndUser')
				->getModel($this->ownerData);
		} catch (Exception $exception) {
			throw new tx_oelib_Exception_NotFound('There is no owner for the current realty object.', 1333035795);
		}

		return $result;
	}

	/**
	 * Returns whether an owner is set for the current realty object.
	 *
	 * @return boolean TRUE if the object has an owner, FALSE otherwise
	 */
	public function hasOwner() {
		return ($this->getAsInteger('owner') > 0);
	}

	/**
	 * Returns a value for a given key from a loaded realty object. If the key
	 * does not exist or no object is loaded, an empty string is returned.
	 *
	 * @param string $key key of value to fetch from current realty object, must not be empty
	 *
	 * @return mixed corresponding value or an empty string if the key
	 *               does not exist
	 */
	public function getProperty($key) {
		return $this->get($key);
	}

	/**
	 * Returns all data from a realty object as an array.
	 *
	 * @return array current realty object data, may be empty
	 */
	protected function getAllProperties() {
		$result = array();

		foreach (
			array_keys(tx_oelib_db::getColumnsInTable(REALTY_TABLE_OBJECTS))
		as $key) {
			if ($this->existsKey($key)) {
				$result[$key] = $this->get($key);
			} elseif (($key == 'uid') && $this->hasUid()) {
				$result['uid'] = $this->getUid();
			}
		}

		return $result;
	}

	/**
	 * Sets an existing key from a loaded realty object to a value. Does nothing
	 * if the key does not exist in the current realty object or no object is
	 * loaded.
	 * Reloads the owner's data.
	 *
	 * @param string $key key of the value to set in current realty object, must not be empty and must not be 'uid'
	 * @param mixed $value value to set, must be either numeric or a string (also empty) or of boolean, may not be NULL
	 *
	 * @return void
	 */
	public function setProperty($key, $value) {
		$this->set($key, $value);
	}

	/**
	 * Sets an existing key from a loaded realty object to a value. Does nothing
	 * if the key does not exist in the current realty object or no object is
	 * loaded.
	 * Reloads the owner's data.
	 *
	 * @param string $key key of the value to set in current realty object, must not be empty and must not be 'uid'
	 * @param mixed $value value to set, must be either numeric or a string (also empty) or of boolean, may not be NULL
	 *
	 * @throws InvalidArgumentException
	 *
	 * @return void
	 */
	public function set($key, $value) {
		if ($this->isVirgin()
			|| !$this->isAllowedValue($value)
			|| !$this->isAllowedKey($key)
		) {
			return;
		}

		if ($key == 'uid') {
			throw new InvalidArgumentException('The key must not be "uid".', 1333035810);
		}

		parent::set($key, $value);
	}

	/**
	 * Sets the current realty object to deleted.
	 *
	 * @return void
	 */
	public function setToDeleted() {
		parent::setToDeleted();
	}

	/**
	 * Checks whether a value is either numeric or a string or of boolean.
	 *
	 * @param mixed $value value to check
	 *
	 * @return boolean TRUE if the value is either numeric or a string
	 *                 or of boolean, FALSE otherwise
	 */
	private function isAllowedValue($value) {
		return (is_numeric($value) || is_string($value) || is_bool($value));
	}

	/**
	 * Checks whether all required fields are set in the realty object.
	 * $this->requiredFields must have already been loaded.
	 *
	 * @return array array of missing required fields, empty if all
	 *               required fields are set
	 */
	public function checkForRequiredFields() {
		$missingFields = array();

		foreach ($this->requiredFields as $requiredField) {
			if (!$this->existsKey($requiredField)) {
				$missingFields[] = $requiredField;
			}
		}

		return $missingFields;
	}

	/**
	 * Checks whether $key is in the list of allowed field names.
	 *
	 * @param string $key key to be checked for being an allowed field name, must not be empty
	 *
	 * @return boolean TRUE if key is an allowed field name for a realty object,
	 *                 FALSE otherwise
	 */
	public function isAllowedKey($key) {
		return tx_oelib_db::tableHasColumn(REALTY_TABLE_OBJECTS, $key);
	}

	/**
	 * Sets the required fields for the current object.
	 *
	 * @param array $fields required fields, may be empty
	 *
	 * @return void
	 */
	public function setRequiredFields(array $fields) {
		$this->requiredFields = $fields;
	}

	/**
	 * Gets the required fields for the current object.
	 *
	 * @return array required fields, may be empty
	 */
	public function getRequiredFields() {
		return $this->requiredFields;
	}

	/**
	 * Prepares the realty object for insertion and inserts records to
	 * the related tables.
	 * It writes the values of 'city', 'apartment_type', 'house_type',
	 * 'district', 'pets' and 'garage_type', in case they are defined, as
	 * records to their own tables and stores the UID of the record instead of
	 * the value.
	 *
	 * @return void
	 */
	protected function prepareInsertionAndInsertRelations() {
		foreach (self::$propertyTables as $currentTable => $currentProperty) {
			$uidOfProperty = $this->insertPropertyToOwnTable(
				$currentProperty,
				$currentTable
			);

			if ($uidOfProperty > 0) {
				$this->setProperty($currentProperty, $uidOfProperty);
				$this->getReferenceIndex()->updateRefIndexTable(
					$currentTable,
					$uidOfProperty
				);
			}
		}
	}

	/**
	 * Gets a cached instance of the reference index (and creates it, if
	 * necessary).
	 *
	 * @return t3lib_refindex a cached reference index instance
	 */
	private function getReferenceIndex() {
		if (!self::$referenceIndex) {
			self::$referenceIndex = t3lib_div::makeInstance('t3lib_refindex');
		}

		return self::$referenceIndex;
	}

	/**
	 * Inserts a property of a realty object into the table $table. Returns the
	 * UID of the newly created record or an empty string if the value to insert
	 * is not set.
	 *
	 * @param string $key key of property to insert from current realty object, must not be empty
	 * @param string $table name of a table where to insert the property, must not be empty
	 *
	 * @return integer UID of the newly created record, 0 if no record was
	 *                 created
	 */
	private function insertPropertyToOwnTable($key, $table) {
		// If the property is not defined or the value is an empty string or
		// zero, no record will be created.
		if (!$this->existsKey($key)
			|| in_array($this->get($key), array('0', '', 0), TRUE)
		) {
			return 0;
		}

		// If the value is a non-zero integer, the relation has already been
		// inserted.
		if ($this->hasInteger($key)) {
			return $this->getAsInteger($key);
		}

		$propertyArray = array('title' => $this->getAsString($key));
		$uidOfProperty = 0;

		if ($this->recordExistsInDatabase($propertyArray, $table)) {
			$uidOfProperty = $this->getRecordUid($propertyArray, $table);
		} else {
			$uidOfProperty = $this->createNewDatabaseEntry($propertyArray, $table);
		}

		return $uidOfProperty;
	}

	/**
	 * Inserts entries for images of the current realty object to the database
	 * table 'tx_realty_images' and deletes all former image entries.
	 *
	 * @param integer $overridePid
	 *        PID for new object and image records (omit this parameter to use the PID set in the global configuration)
	 *
	 * @return void
	 */
	private function refreshImageEntries($overridePid = 0) {
 		if ($this->oldImagesNeedToGetDeleted) {
 			$this->discardExistingImages();
 		}

		if (!$this->imagesNeedToGetSaved) {
			return;
		}

		$mapper = tx_oelib_MapperRegistry::get('tx_realty_Mapper_Image');

		$pageUid = ($overridePid > 0)
			? $overridePid
			: tx_oelib_configurationProxy::getInstance('realty')
				->getAsInteger('pidForRealtyObjectsAndImages');

		$sorting = 0;
		foreach ($this->getImages() as $image) {
			if ($image->isDead()) {
				continue;
			}
			$image->setObject($this);
			$image->setPageUid($pageUid);
			$image->setSorting($sorting);

			$mapper->save($image);
			$sorting++;
		}

		$this->imagesNeedToGetSaved = FALSE;
	}

	/**
	 * Deletes all images that are related to this realty object from the
	 * database.
	 *
	 * This function does not affect in-memory images that have not been
	 * persisted to the database yet.
	 *
	 * @return void
	 */
	protected function discardExistingImages() {
		$mapper = tx_oelib_MapperRegistry::get('tx_realty_Mapper_Image');
		foreach ($mapper->findAllByRelation($this, 'object') as $image) {
			$mapper->delete($image);
 		}

 		$this->oldImagesNeedToGetDeleted = FALSE;
	}

	/**
	 * Inserts entries for documents of the current realty object to the database
	 * table 'tx_realty_documents' and deletes all former document entries.
	 *
	 * @param integer $overridePid
	 *        PID for new object, image and documents records (omit this
	 *        parameter to use the PID set in the global configuration)
	 *
	 * @return void
	 */
	private function refreshDocumentEntries($overridePid = 0) {
 		if ($this->oldDocumentsNeedToGetDeleted) {
 			$this->discardExistingDocuments();
 		}

		if (!$this->documentsNeedToGetSaved) {
			return;
		}

		$mapper = tx_oelib_MapperRegistry::get('tx_realty_Mapper_Document');

		$pageUid = ($overridePid > 0)
			? $overridePid
			: tx_oelib_configurationProxy::getInstance('realty')
				->getAsInteger('pidForRealtyObjectsAndImages');

		$sorting = 0;
		foreach ($this->getDocuments() as $document) {
			if ($document->isDead()) {
				continue;
			}
			$document->setObject($this);
			$document->setPageUid($pageUid);
			$document->setSorting($sorting);

			$mapper->save($document);
			$sorting++;
		}

		$this->documentsNeedToGetSaved = FALSE;
	}

	/**
	 * Deletes all documents that are related to this realty object from the
	 * database.
	 *
	 * This function does not affect in-memory documents that have not been
	 * persisted to the database yet.
	 *
	 * @return void
	 */
	protected function discardExistingDocuments() {
		$mapper = tx_oelib_MapperRegistry::get('tx_realty_Mapper_Document');
		foreach ($mapper->findAllByRelation($this, 'object') as $document) {
			$mapper->delete($document);
 		}

 		$this->oldDocumentsNeedToGetDeleted = FALSE;
	}

	/**
	 * Gets the images attached to this object.
	 *
	 * All images attached to this object are returned, except images of file type PDF or PS.
	 *
	 * @see https://bugs.oliverklee.com/show_bug.cgi?id=3716
	 *
	 * @return tx_oelib_List<tx_realty_Model_Image>
	 *         the attached images, will be empty if this object has no images
	 */
	public function getImages() {
		/** @var $image tx_realty_Model_Image */
		foreach ($this->images as $image) {
			if (!$image->isDead()) {
				$imageExtension = pathinfo($image->getFileName(), PATHINFO_EXTENSION);
				if (!$this->isValidImageExtension($imageExtension)) {
					$this->images->purgeCurrent();
				}
			}
		}

		return $this->images;
	}

	/**
	 * Gets the related documents.
	 *
	 * @return tx_oelib_List<tx_realty_Model_Document>
	 *         the related documents, will be empty if this object has no
	 *         documents
	 */
	public function getDocuments(){
		return $this->documents;
	}

	/**
	 * Reads the images attached to this realty object into $this->images.
	 *
	 * @return void
	 */
	private function retrieveAttachedImages() {
		if (!$this->identifyObjectAndSetUid()) {
			return;
		}
		if (!$this->hasInteger('images')) {
			return;
		}

		$images = tx_oelib_MapperRegistry::get('tx_realty_Mapper_Image')
			->findAllByRelation($this, 'object');
		$images->sortBySorting();

		$this->images = $images;
	}

	/**
	 * Reads the documents attached to this realty object into $this->documents.
	 *
	 * @return void
	 */
	private function retrieveAttachedDocuments() {
		if (!$this->identifyObjectAndSetUid()) {
			return;
		}
		if (!$this->hasInteger('documents')) {
			return;
		}

		$documents = tx_oelib_MapperRegistry::get('tx_realty_Mapper_Document')
			->findAllByRelation($this, 'object');
		$documents->sortBySorting();

		$this->documents = $documents;
	}

	/**
	 * Adds a new image record to the currently loaded object.
	 *
	 * Note: This function does not check whether $fileName points to a file.
	 *
	 * @param string $caption
	 *        caption for the new image record, may be empty
	 * @param string $fileName
	 *        name of the image in the upload directory, must not be empty
	 * @param integer $position
	 *        the position of the image, must be between 0 and 4
	 * @param string $thumbnailFileName
	 *        name of the separate thumbnail in the upload directory
	 *
	 * @throws BadMethodCallException
	 *
	 * @return integer key of the newly created record, will be >= 0
	 */
	public function addImageRecord(
		$caption, $fileName, $position = 0, $thumbnailFileName = ''
	) {
		if ($this->isVirgin()) {
			throw new BadMethodCallException('A realty record must be loaded before images can be appended.', 1333035831);
		}

		$this->markAsLoaded();

		$this->set('images', $this->getAsInteger('images') + 1);

		/** @var $image tx_realty_Model_Image */
		$image = t3lib_div::makeInstance('tx_realty_Model_Image');
		$image->setTitle($caption);
		if ($fileName != '') {
			$image->setFileName($fileName);
		}
		$image->setPosition($position);
		$image->setThumbnailFileName($thumbnailFileName);

		$this->images->add($image);

		$this->imagesNeedToGetSaved = TRUE;

		return $this->images->count() - 1;
	}

	/**
	 * Adds a new document to the currently loaded object.
	 *
	 * Note: This function does not check whether $fileName points to a file.
	 *
	 * @param string $title
	 *        title for the new document record, must not be empty
	 * @param string $fileName
	 *        name of the PDF document in the upload directory, must not be empty
	 *
	 * @throws BadMethodCallException
	 *
	 * @return integer
	 *         zero-based index of the newly created document, will be >= 0
	 */
	public function addDocument($title, $fileName) {
		if ($this->isVirgin()) {
			throw new BadMethodCallException('A realty record must be loaded before documents can be appended.', 1333035851);
		}

		$this->markAsLoaded();

		$this->set('documents', $this->getAsInteger('documents') + 1);

		/** @var $document tx_realty_Model_Document */
		$document = t3lib_div::makeInstance('tx_realty_Model_Document');
		if ($title != '') {
			$document->setTitle($title);
		}
		if ($fileName != '') {
			$document->setFileName($fileName);
		}
		$this->documents->add($document);

		$this->documentsNeedToGetSaved = TRUE;

		return $this->documents->count() - 1;
	}

	/**
	 * Marks an image record of the currently loaded object as deleted. This
	 * record will be marked as deleted in the database when the object is
	 * written to the database.
	 *
	 * @param integer $imageKey key of the image record to mark as deleted, must be a key of the image data array and must be >= 0
	 *
	 * @throws BadMethodCallException
	 * @throws tx_oelib_Exception_NotFound
	 *
	 * @return void
	 */
	public function markImageRecordAsDeleted($imageKey) {
		if ($this->isVirgin()) {
			throw new BadMethodCallException(
				'A realty record must be loaded before images can be marked as deleted.', 1333035867
			);
		}

		$image = $this->images->at($imageKey);

		if ($image == NULL) {
			throw new tx_oelib_Exception_NotFound('The image record does not exist.', 1333035899);
		}

		tx_oelib_MapperRegistry::get('tx_realty_Mapper_Image')->delete($image);

		$this->setAsInteger('images', $this->getAsInteger('images') - 1);
	}

	/**
	 * Marks an document record of the currently loaded object as deleted. This
	 * record will be marked as deleted in the database when the object is
	 * written to the database.
	 *
	 * @param integer $key
	 *        key of the document record to mark as deleted, must be a key of
	 *        the document data array and must be >= 0
	 *
	 * @throws BadMethodCallException
	 * @throws tx_oelib_Exception_NotFound
	 *
	 * @return void
	 */
	public function deleteDocument($key) {
		if ($this->isVirgin()) {
			throw new BadMethodCallException('A realty record must be loaded before documents can be deleted.', 1333035926);
		}

		$document = $this->documents->at($key);

		if ($document == NULL) {
			throw new tx_oelib_Exception_NotFound('The document does not exist.', 1333035940);
		}

		tx_oelib_MapperRegistry::get('tx_realty_Mapper_Document')
			->delete($document);

		$this->setAsInteger('documents', $this->getAsInteger('documents') - 1);
	}

	/**
	 * Creates a new record with the contents of the array $realtyData, unless
	 * it is empty, in the database. All fields to insert must already exist in
	 * the database.
	 * The values for PID, 'tstamp' and 'crdate' are provided by this function.
	 *
	 * @param array $realtyData
	 *        database column names as keys, must not be empty and must not contain the key 'uid'
	 * @param string $table
	 *        name of the database table, must not be empty
	 * @param integer $overridePid PID
	 *        for new realty and image records (omit this parameter to use the PID set in the global configuration)
	 *
	 * @throws InvalidArgumentException
	 *
	 * @return integer UID of the new database entry, will be zero if no new
	 *                 record could be created, will be -1 if the deleted flag
	 *                 was set
	 */
	protected function createNewDatabaseEntry(
		array $realtyData, $table = REALTY_TABLE_OBJECTS, $overridePid = 0
	) {
		if (empty($realtyData)) {
			return 0;
		}
		if ($realtyData['deleted']) {
			return -1;
		}

		if (isset($realtyData['uid'])) {
			throw new InvalidArgumentException('The column "uid" must not be set in $realtyData.', 1333035957);
		}

		$dataToInsert = $realtyData;
		$pid = tx_oelib_configurationProxy::getInstance('realty')->
			getAsInteger('pidForAuxiliaryRecords');
		if (($pid == 0) || ($table == REALTY_TABLE_OBJECTS)) {
			if ($overridePid > 0) {
				$pid = $overridePid;
			} else {
				$pid = tx_oelib_configurationProxy::getInstance('realty')->
					getAsInteger('pidForRealtyObjectsAndImages');
			}
		}

		$dataToInsert['pid'] = $pid;
		$dataToInsert['tstamp'] = $GLOBALS['SIM_EXEC_TIME'];
		$dataToInsert['crdate'] = $GLOBALS['SIM_EXEC_TIME'];
		// allows an easy removal of records created during the unit tests
		$dataToInsert['is_dummy_record'] = $this->isDummyRecord;

		return tx_oelib_db::insert($table, $dataToInsert);
	}

	/**
	 * Updates an existing realty record entry. The provided data must contain the
	 * element 'uid'.
	 *
	 * The value for 'tstamp' is set automatically.
	 *
	 * @param array $realtyData
	 *        database column names as keys to update an already existing entry,
	 *        must at least contain an element with the key 'uid'
	 *
	 * @throws InvalidArgumentException
	 *
	 * @return void
	 */
	protected function updateDatabaseEntry(array $realtyData) {
		if ($realtyData['uid'] <= 0) {
			throw new InvalidArgumentException('$data needs to contain a UID > 0.', 1333035969);
 		}

		$dataForUpdate = $realtyData;
		$dataForUpdate['tstamp'] = $GLOBALS['SIM_EXEC_TIME'];

		tx_oelib_db::update(
			'tx_realty_objects',
			'uid = ' . $dataForUpdate['uid'],
			$dataForUpdate
		);
	}

	/**
	 * Checks whether a record exists in the database.
	 * If $dataArray has got an element named 'uid', the database match is
	 * searched by this UID. Otherwise, the database match is searched by the
	 * list of alternative keys.
	 * The result will be TRUE if either the UIDs matched or if all the elements
	 * of $dataArray which correspond to the list of alternative keys match the
	 * a database record.
	 *
	 * @param array $dataArray
	 *        Data array with database column names and the corresponding values.
	 *        The database match is searched by all these keys' values in case there is no UID within the array.
	 * @param string $table
	 *        name of table where to find out whether an entry yet exists, must not be empty
	 *
	 * @return boolean True if the UID in the data array equals an existing
	 *                 entry or if the value of the alternative key was found in
	 *                 the database. False in any other case, also if the
	 *                 database result could not be fetched or if neither 'uid'
	 *                 nor $alternativeKey were elements of $dataArray.
	 */
	protected function recordExistsInDatabase(
		array $dataArray, $table = REALTY_TABLE_OBJECTS
	) {
		$databaseResult = $this->compareWithDatabase(
			'COUNT(*) AS number', $dataArray, $table
		);

		return ($databaseResult['number'] >= 1);
	}

	/**
	 * Sets the UID for a realty object if it exists in the database and has no
	 * UID yet.
	 *
	 * @return boolean TRUE if the record has a UID, FALSE otherwise
	 */
	private function identifyObjectAndSetUid() {
		if ($this->hasUid()) {
			return TRUE;
		}

		$dataArray = array();
		foreach (array('object_number', 'language', 'openimmo_obid') as $key) {
			if ($this->existsKey($key)) {
				$dataArray[$key] = $this->get($key);
			}
		}
		if (!empty($dataArray)) {
			$this->setUid($this->getRecordUid($dataArray));
		}

		return $this->hasUid();
	}

	/**
	 * Returns the UID of a database record if all elements in $dataArray match
	 * a database entry in $table.
	 *
	 * @param array $dataArray
	 *        the data of an entry which already exists in database by which the existence will be proven, must not be empty
	 * @param string $table
	 *        name of the table where to find out whether an entry already exists
	 *
	 * @return integer the UID of the record identified in the database, zero if
	 *                 none was found
	 */
	private function getRecordUid(
		array $dataArray, $table = REALTY_TABLE_OBJECTS
	) {
		$databaseResultRow = $this->compareWithDatabase(
			'uid', $dataArray, $table
		);

		return (!empty($databaseResultRow) ? $databaseResultRow['uid'] : 0);
	}

	/**
	 * Retrieves an associative array of data and returns the database result
	 * according to $whatToSelect of the attempt to find matches for the list of
	 * $keys. $keys is a comma-separated list of the database collumns which
	 * should be compared with the corresponding values in $dataArray.
	 *
	 * @param string $whatToSelect
	 *        list of fields to select from the database table (part of the sql-query right after SELECT), must not be empty
	 * @param array $dataArray
	 *        data which to take for the database comparison, must not be empty
	 * @param string $table
	 *        table name, must not be empty
	 *
	 * @return array database result row in an array, will be empty if
	 *               no matching record was found
	 */
	private function compareWithDatabase(
		$whatToSelect, array $dataArray, $table
	) {
		$result = FALSE;

		$whereClauseParts = array();
		foreach (array_keys($dataArray) as $key) {
			$whereClauseParts[] = $key . '=' .
				$GLOBALS['TYPO3_DB']->fullQuoteStr($dataArray[$key], $table);
		}

		$showHidden = -1;
		if (($table == REALTY_TABLE_OBJECTS) && $this->canLoadHiddenObjects) {
			$showHidden = 1;
		}

		try {
			$result = tx_oelib_db::selectSingle(
				$whatToSelect,
				$table,
				implode(' AND ', $whereClauseParts) .
					tx_oelib_db::enableFields($table, $showHidden)
			);
		} catch (tx_oelib_Exception_EmptyQueryResult $exception) {
			$result = array();
		}

		return $result;
	}

	/**
	 * Gets the street.
	 *
	 * @return string the street, might be empty
	 */
	public function getStreet() {
		return $this->getAsString('street');
	}

	/**
	 * Checks whether this object has a non-empty street set.
	 *
	 * @return boolean TRUE if this object has a street set, FALSE otherwise
	 */
	public function hasStreet() {
		return $this->hasString('street');
	}

	/**
	 * Sets the street.
	 *
	 * @param string $street the street, may be empty
	 *
	 * @return void
	 */
	public function setStreet($street) {
		return $this->setAsString('street', $street);
	}

	/**
	 * Gets this tender's ZIP.
	 *
	 * @return string the ZIP of this tender, will be empty if the tender has none
	 */
	public function getZip() {
		return $this->getAsString('zip');
	}

	/**
	 * Checks whether this tender has a non-empty ZIP code set.
	 *
	 * @return boolean TRUE if this tender has a ZIP code set, FALSE otherwise
	 */
	public function hasZip() {
		return $this->hasString('zip');
	}

	/**
	 * Sets the ZIP code.
	 *
	 * @param string $zip the ZIP code, may be empty
	 *
	 * @return void
	 */
	public function setZip($zip) {
		$this->setAsString('zip', $zip);
	}

	/**
	 * Returns the city of this object.
	 *
	 * @return tx_realty_Model_City the related city, will be NULL if there is none
	 */
	public function getCity() {
		if (!$this->hasCity()) {
			return NULL;
		}

		return tx_oelib_MapperRegistry::get('tx_realty_Mapper_City')->find($this->getAsInteger('city'));
	}

	/**
	 * Checks whether this object has a city assigned.
	 *
	 * @return boolean whether this object has a city assigned
	 */
	public function hasCity() {
		return $this->hasInteger('city');
	}

	/**
	 * Returns the country of this object.
	 *
	 * @return tx_realty_Model_Country the related country, will be NULL if there is none
	 */
	public function getCountry() {
		if (!$this->hasCountry()) {
			return NULL;
		}

		return tx_oelib_MapperRegistry::get('tx_oelib_Mapper_Country')->find($this->getAsInteger('country'));
	}

	/**
	 * Checks whether this object has a country assigned.
	 *
	 * @return boolean whether this object has a country assigned
	 */
	public function hasCountry() {
		return $this->hasInteger('country');
	}

	/**
	 * Gets a field of a related property of the object.
	 *
	 * @throws InvalidArgumentException
	 *         if $key is not within "city", "apartment_type", "house_type", "district", "pets", "garage_type" and "country"
	 *
	 * @param string $key key of this object's property, must not be empty
	 * @param string $titleField key of the property's field to get, must not be empty
	 *
	 * @return string the title of the related property with the UID found in
	 *                in this object's field $key or an empty string if this
	 *                object does not have the property set
	 */
	public function getForeignPropertyField($key, $titleField = 'title') {
		$tableName = ($key == 'country')
			? STATIC_COUNTRIES : array_search($key, self::$propertyTables);

		if ($tableName === FALSE) {
			throw new InvalidArgumentException(
				'$key must be within "city", "apartment_type", "house_type", "district", "pets", ' .
					'"garage_type", "country", but actually is "' . $key . '".',
				1333035988
			);
		}

		$property = $this->get($key);
		if (($property === '0') || ($property === 0)) {
			return '';
		}

		// In case property is an integer, it is expected to be a UID, else
		// the foreign property's title is assumed to be directly provided.
		if (!preg_match('/^\d+$/', $property)) {
			return $property;
		}

		try {
			$row = tx_oelib_db::selectSingle(
				$titleField,
				$tableName,
				'uid = ' . $property . tx_oelib_db::enableFields($tableName)
			);
			$result = $row[$titleField];
		} catch (tx_oelib_Exception_EmptyQueryResult $exception) {
			$result = '';
		}

		return $result;
	}

	/**
	 * Returns this object's address formatted for a geo lookup, for example "53117 Bonn, DE". Any part of this address might be
	 * missing, though.
	 *
	 * @throws BadMethodCallException
	 *
	 * @return string this object's address formatted for a geo lookup, will be empty if this object has no address
	 */
	public function getGeoAddress() {
		if (!$this->hasCity()) {
			return '';
		}
		$zipAndCity = trim($this->getZip() . ' ' . $this->getCity()->getTitle());

		$addressParts = array();

		if ($this->hasStreet()) {
			$addressParts[] = $this->getStreet();
		}

		$addressParts[] = $zipAndCity;

		if ($this->hasCountry()) {
			$addressParts[] = $this->getCountry()->getIsoAlpha2Code();
		}

		return implode(', ', $addressParts);
	}

	/**
	 * Checks whether this object has a non-empty address suitable for a geo lookup.
	 *
	 * @return boolean TRUE if this object has a non-empty address, FALSE otherwise
	 */
	public function hasGeoAddress() {
		return $this->getGeoAddress() !== '';
	}

	/**
	 * Retrieves this object's coordinates.
	 *
	 * @return array
	 *         this object's geo coordinates using the keys "latitude" and "longitude",
	 *         will be empty if this object has no coordinates
	 */
	public function getGeoCoordinates() {
		if (!$this->hasGeoCoordinates()) {
			return array();
		}

		return array(
			'latitude' => $this->getLatitude(),
			'longitude' => $this->getLongitude(),
		);
	}

	/**
	 * Checks whether this object has non-empty coordinates.
	 *
	 * Note: This function does not check that there are no geo errors.
	 *
	 * @return boolean TRUE if this object has both a non-empty longitude and a non-empty latitude, FALSE otherwise
	 */
	public function hasGeoCoordinates() {
		return $this->getAsBoolean('has_coordinates');
	}

	/**
	 * Gets this object's latitude.
	 *
	 * @return float this object's latitude, will be 0.0 if no latitude has been set
	 */
	protected function getLatitude() {
		return $this->getAsFloat('latitude');
	}

	/**
	 * Gets this object's longitude.
	 *
	 * @return float this object's longitude, will be 0.0 if no longitude has been set
	 */
	protected function getLongitude() {
		return $this->getAsFloat('longitude');
	}

	/**
	 * Sets this objects's coordinates and sets the geo error flag to FALSE.
	 *
	 * @param array $coordinates the coordinates, using the keys "latitude" and "longitude", the array values must not be empty
	 *
	 * @throws InvalidArgumentException
	 *
	 * @return void
	 */
	public function setGeoCoordinates(array $coordinates) {
		if (!isset($coordinates['latitude']) || !isset($coordinates['longitude'])) {
			throw new InvalidArgumentException('setGeoCoordinates requires both a latitude and a longitude.', 1340376055);
		}

		$this->setLatitude($coordinates['latitude']);
		$this->setLongitude($coordinates['longitude']);
		$this->setAsBoolean('has_coordinates', TRUE);
		$this->clearGeoError();
	}

	/**
	 * Sets this object's latitude.
	 *
	 * @param float $latitude this object's latitude
	 *
	 * @return void
	 */
	protected function setLatitude($latitude) {
		$this->setAsString('latitude', number_format($latitude, 6, '.', ''));
	}

	/**
	 * Sets this object's longitude.
	 *
	 * @param float $longitude this object's longitude
	 *
	 * @return void
	 */
	protected function setLongitude($longitude) {
		$this->setAsString('longitude', number_format($longitude, 6, '.', ''));
	}

	/**
	 * Purges this object's geo coordinates.
	 *
	 * Note: Calling this function has no influence on this object's geo error status.
	 *
	 * @return void
	 */
	public function clearGeoCoordinates() {
		$this->setGeoCoordinates(array('latitude' => 0.0, 'longitude' => 0.0));
		$this->setAsBoolean('has_coordinates', FALSE);
	}

	/**
	 * Checks whether there has been a problem with this object's geo coordinates.
	 *
	 * Note: This function only checks whether there has been an error with the coordinates, not whether this object actually has
	 * coordinates.
	 *
	 * @return boolean TRUE if there has been an error, FALSE otherwise
	 */
	public function hasGeoError() {
		return $this->getAsBoolean('coordinates_problem');
	}

	/**
	 * Marks this object as having an error with the geo coordinates.
	 *
	 * @return void
	 */
	public function setGeoError() {
		$this->setAsBoolean('coordinates_problem', TRUE);
	}

	/**
	 * Marks this object as not having an error with the geo coordinates.
	 *
	 * @return void
	 */
	public function clearGeoError() {
		$this->setAsBoolean('coordinates_problem', FALSE);
	}

	/**
	 * Gets this object's title.
	 *
	 * @return string this object's title, will be empty if this object does not have a title
	 */
	public function getTitle() {
		return $this->getAsString('title');
	}

	/**
	 * Sets the title of this object.
	 *
	 * @param string $title the title to set, must not be empty
	 *
	 * @return void
	 */
	public function setTitle($title) {
		$this->setAsString('title', $title);
	}

	/**
	 * Gets this object's title, cropped after cropSize characters. If no
	 * cropSize is given or if it is 0 the title will be cropped after CROP_SIZE
	 * characters. The title will get an ellipsis at the end if the full title
	 * was long enough to be cropped.
	 *
	 * @param integer $cropSize the number of characters after which the title should be cropped, must be >= 0
	 *
	 * @return string this object's cropped title, will be empty if this
	 *                object does not have a title
	 */
	public function getCroppedTitle($cropSize = 0) {
		$fullTitle = $this->getTitle();
		$interceptPoint = ($cropSize > 0) ? $cropSize : self::CROP_SIZE;

		return $this->charsetConversion->crop(
			$this->renderCharset, $fullTitle, $interceptPoint, '…'
		);
	}

	/**
	 * Returns the current object's address as HTML (separated by <br />) with
	 * the granularity defined in the field "show_address".
	 *
	 * @return string the address of the current object, will not be empty
	 */
	public function getAddressAsHtml() {
		return implode('<br />', $this->getAddressParts());
	}

	/**
	 * Returns whether the full address for this object should be visible.
	 *
	 * @return boolean whether the full address for this object should be visible
	 */
	public function getShowAddress() {
		return $this->getAsBoolean('show_address');
	}

	/**
	 * Sets whether the full address for this object should be visible.
	 *
	 * @param boolean $showIt whether the full address for this object should be visible
	 *
	 * @return void
	 */
	public function setShowAddress($showIt) {
		$this->setAsBoolean('show_address', $showIt);
	}

	/**
	 * Builds the address for later formatting with the granularity defined in
	 * the field "show_address".
	 *
	 * @return string[]
	 *         the htmlspecialchared address parts, will not be empty
	 */
	protected function getAddressParts() {
		$result = array();

		if ($this->getShowAddress() && ($this->getAsString('street') != '')
		) {
			$result[] = htmlspecialchars($this->getAsString('street'));
		}

		$result[] = htmlspecialchars(trim(
			$this->getAsString('zip') . ' ' .
				$this->getForeignPropertyField('city') . ' ' .
				$this->getForeignPropertyField('district')
		));

		$country = $this->getForeignPropertyField('country', 'cn_short_local');
		if ($country != '') {
			$result[] = $country;
		}

		return $result;
	}

	/**
	 * Returns the objects address as a single line, with comma separated values
	 * and with the granularity defined in the field "show_address".
	 *
	 * @return string the address with comma separated values, will not be empty
	 */
	public function getAddressAsSingleLine() {
		return implode(', ', $this->getAddressParts());
	}

	/**
	 * Returns the name of the contact person.
	 *
	 * @return string the name of the contact person, might be empty
	 */
	public function getContactName() {
		return ($this->usesContactDataOfOwner())
			? $this->owner->getName()
			: $this->getAsString('contact_person');
	}

	/**
	 * Returns the e-mail address of the contact person.
	 *
	 * @return string the e-mail address of the contact person, might be empty
	 */
	public function getContactEMailAddress() {
		return ($this->usesContactDataOfOwner())
			? $this->owner->getEMailAddress()
			: $this->getAsString('contact_email');
	}

	/**
	 * Returns the city of the contact person.
	 *
	 * @return string the city of the contact person, will be empty if no city
	 *                was set or the contact data source is this object
	 */
	public function getContactCity() {
		return ($this->usesContactDataOfOwner())
			? $this->owner->getCity()
			: '';
	}

	/**
	 * Returns the street of the contact person.
	 *
	 * @return string the street of the contact person, may be multi-line, will
	 *                be empty if no street was set or the contact data source
	 *                is this object
	 */
	public function getContactStreet() {
		return ($this->usesContactDataOfOwner())
			? $this->owner->getStreet()
			: '';
	}

	/**
	 * Returns the ZIP code of the contact person.
	 *
	 * @return string the ZIP code of the contact person, will be empty if no
	 *                ZIP code was set or the contact data source is this object
	 */
	public function getContactZip() {
		return ($this->usesContactDataOfOwner())
			? $this->owner->getZip()
			: '';
	}

	/**
	 * Returns the homepage of the contact person.
	 *
	 * @return string the homepage of the contact person, will be empty if no
	 *                homepage was set or the contact data source is this object
	 */
	public function getContactHomepage() {
		return ($this->usesContactDataOfOwner())
			? $this->owner->getHomepage()
			: '';
	}

	/**
	 * Returns the telephone number of the contact person.
	 *
	 * If the contact data source is this object, first the direct extension
	 * will be displayed. If this is empty the switchboard will be displayed. If
	 * this is also empty, the contact phone will be returned.
	 *
	 * @return string the telephone number of the contact person, will be empty
	 *                if no telephone number was set
	 */
	public function getContactPhoneNumber() {
		if ($this->usesContactDataOfOwner()) {
			return $this->owner->getPhoneNumber();
		}

		if ($this->hasString('phone_direct_extension')) {
			$result = $this->getContactDirectExtension();
		} elseif ($this->hasString('phone_switchboard')) {
			$result = $this->getContactSwitchboard();
		} else {
			$result = '';
		}

		return $result;
	}

	/**
	 * Checks whether the contact data of this object should be retrieved from
	 * the owner FE user.
	 *
	 * If a front-end user should be used as owner, the owner will be stored in
	 * $this->owner.
	 *
	 * @return boolean TRUE if the contact data should be fetched from the owner
	 *                 FE user, FALSE otherwise
	 */
	private function usesContactDataOfOwner() {
		$useContactDataOfOwner =
			$this->getAsInteger('contact_data_source')
				== REALTY_CONTACT_FROM_OWNER_ACCOUNT;

		if ($useContactDataOfOwner && $this->owner === NULL) {
			$this->owner
				= tx_oelib_MapperRegistry::get('tx_realty_Mapper_FrontEndUser')
					->find($this->getAsInteger('owner'));
		}

		return $useContactDataOfOwner;
	}

	/**
	 * Sets the current charset in $this->renderCharset and the charset
	 * conversion instance in $this->$charsetConversion.
	 *
	 * @return void
	 */
	private function initializeCharsetConversion() {
		if (isset($GLOBALS['TSFE'])) {
			$this->renderCharset = $GLOBALS['TSFE']->renderCharset;
			$this->charsetConversion = $GLOBALS['TSFE']->csConvObj;
		} elseif (isset($GLOBALS['LANG'])) {
			$this->renderCharset = $GLOBALS['LANG']->charset;
			$this->charsetConversion = $GLOBALS['LANG']->csConvObj;
		} else {
			throw new RuntimeException('There was neither a front end nor a back end detected.', 1333036016);
		}
	}

	/**
	 * Returns the switchboard phone number of the contact person stored in this
	 * object.
	 *
	 * @return string the switchboard phone number of the contact person, will
	 *                be empty if no switchboard phone number has been set
	 */
	public function getContactSwitchboard() {
		return $this->getAsString('phone_switchboard');
	}

	/**
	 * Returns the direct extension phone number of the contact person stored in
	 * this object.
	 *
	 * @return string the direct extension phone number of the contact person,
	 *                will be empty if no direct extension phone number has been
	 *                set
	 */
	public function getContactDirectExtension() {
		return $this->getAsString('phone_direct_extension');
	}

	/**
	 * Gets this object's status.
	 *
	 * @return integer
	 *         this object's status, will be either STATUS_VACANT,
	 *         STATUS_RESERVED, STATUS_SOLD or STATUS_RENTED
	 */
	public function getStatus() {
		return $this->getAsInteger('status');
	}

	/**
	 * Checks whether this object is rented or sold.
	 *
	 * @return boolean
	 *         TRUE if this object is rented or sold, FALSE otherwise
	 */
	public function isRentedOrSold() {
		return ($this->getStatus() == self::STATUS_RENTED)
			|| ($this->getStatus() == self::STATUS_SOLD);
	}

	/**
	 * Gets the distance to the sea in meters.
	 *
	 * @return integer the distance to the sea, will be > 0 if has been set or 0 if none has been set
	 */
	public function getDistanceToTheSea() {
		return $this->getAsInteger('distance_to_the_sea');
	}

	/**
	 * Checks whether this object has a non-zero distance to the sea.
	 *
	 * @return boolean TRUE if this object has a non-zero distance to the sea, FALSE otherwise
	 */
	public function hasDistanceToTheSea() {
		return $this->hasInteger('distance_to_the_sea');
	}

	/**
	 * Sets the distance to the sea in meters.
	 *
	 * @param integer $distanceInMeters the distance to the sea in meters, must be >= 0
	 *
	 * @throws InvalidArgumentException
	 *
	 * @return void
	 */
	public function setDistanceToTheSea($distanceInMeters) {
		if ($distanceInMeters < 0) {
			throw new InvalidArgumentException(
				'$distanceInMeters must be >= 0, but actually is: ' . $distanceInMeters, 1342813877
			);
		}

		$this->setAsInteger('distance_to_the_sea', $distanceInMeters);
	}
}

if (defined('TYPO3_MODE') && $GLOBALS['TYPO3_CONF_VARS'][TYPO3_MODE]['XCLASS']['ext/realty/Model/class.tx_realty_Model_RealtyObject.php']) {
	include_once($GLOBALS['TYPO3_CONF_VARS'][TYPO3_MODE]['XCLASS']['ext/realty/Model/class.tx_realty_Model_RealtyObject.php']);
}