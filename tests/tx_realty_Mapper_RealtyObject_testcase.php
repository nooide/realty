<?php
/***************************************************************
* Copyright notice
*
* (c) 2009-2010 Saskia Metzler <saskia@merlin.owl.de>
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

require_once(t3lib_extMgm::extPath('oelib') . 'class.tx_oelib_Autoloader.php');

/**
 * Unit tests for the tx_realty_Mapper_RealtyObject class in the "realty"
 * extension.
 *
 * @package TYPO3
 * @subpackage tx_realty
 *
 * @author Saskia Metzler <saskia@merlin.owl.de>
 * @author Oliver Klee <typo3-coding@oliverklee.de>
 */
class tx_realty_Mapper_RealtyObject_testcase extends tx_phpunit_testcase {
	/**
	 * @var tx_realty_Mapper_RealtyObject
	 */
	private $fixture;

	/**
	 * @var tx_oelib_testingFramework
	 */
	private $testingFramework;

	public function setUp() {
		$this->testingFramework = new tx_oelib_testingFramework('tx_realty');
		$this->fixture = new tx_realty_Mapper_RealtyObject();
	}

	public function tearDown() {
		$this->testingFramework->cleanUp();

		$this->fixture->__destruct();

		unset($this->fixture, $this->testingFramework);
	}


	/////////////////////////////////////////
	// Tests concerning the basic functions
	/////////////////////////////////////////

	public function testFindWithUidOfExistingRecordReturnsRealtyObjectInstance() {
		$uid = $this->testingFramework->createRecord(
			'tx_realty_objects', array('title' => 'foo')
		);

		$this->assertTrue(
			$this->fixture->find($uid) instanceof tx_realty_Model_RealtyObject
		);
	}

	public function testGetOwnerForMappedModelReturnsFrontEndUserInstance() {
		$ownerUid = $this->testingFramework->createFrontEndUser();
		$objectUid = $this->testingFramework->createRecord(
			'tx_realty_objects', array('title' => 'foo', 'owner' => $ownerUid)
		);

		$this->assertTrue(
			$this->fixture->find($objectUid)->getOwner()
				instanceof tx_realty_Model_FrontEndUser
		);
	}


	/////////////////////////////////
	// Tests concerning countByCity
	/////////////////////////////////

	/**
	 * @test
	 */
	public function countByCityForNoMatchesReturnsZero() {
		$cityUid = $this->testingFramework->createRecord('tx_realty_cities');
		$city = tx_oelib_MapperRegistry::get('tx_realty_Mapper_City')
			->find($cityUid);

		$this->assertEquals(
			0,
			$this->fixture->countByCity($city)
		);
	}

	/**
	 * @test
	 */
	public function countByCityWithOneMatchReturnsOne() {
		$cityUid = $this->testingFramework->createRecord('tx_realty_cities');
		$city = tx_oelib_MapperRegistry::get('tx_realty_Mapper_City')
			->find($cityUid);

		$this->testingFramework->createRecord(
			'tx_realty_objects', array('city' => $cityUid)
		);

		$this->assertEquals(
			1,
			$this->fixture->countByCity($city)
		);
	}

	/**
	 * @test
	 */
	public function countByCityWithTwoMatchesReturnsTwo() {
		$cityUid = $this->testingFramework->createRecord('tx_realty_cities');
		$city = tx_oelib_MapperRegistry::get('tx_realty_Mapper_City')
			->find($cityUid);

		$this->testingFramework->createRecord(
			'tx_realty_objects', array('city' => $cityUid)
		);
		$this->testingFramework->createRecord(
			'tx_realty_objects', array('city' => $cityUid)
		);

		$this->assertEquals(
			2,
			$this->fixture->countByCity($city)
		);
	}


	/////////////////////////////////////
	// Tests concerning countByDistrict
	/////////////////////////////////////

	/**
	 * @test
	 */
	public function countByDistrictForNoMatchesReturnsZero() {
		$districtUid = $this->testingFramework->createRecord('tx_realty_districts');
		$district = tx_oelib_MapperRegistry::get('tx_realty_Mapper_District')
			->find($districtUid);

		$this->assertEquals(
			0,
			$this->fixture->countByDistrict($district)
		);
	}

	/**
	 * @test
	 */
	public function countByDistrictWithOneMatchReturnsOne() {
		$districtUid = $this->testingFramework->createRecord('tx_realty_districts');
		$district = tx_oelib_MapperRegistry::get('tx_realty_Mapper_District')
			->find($districtUid);

		$this->testingFramework->createRecord(
			'tx_realty_objects', array('district' => $districtUid)
		);

		$this->assertEquals(
			1,
			$this->fixture->countByDistrict($district)
		);
	}

	/**
	 * @test
	 */
	public function countByDistrictWithTwoMatchesReturnsTwo() {
		$districtUid = $this->testingFramework->createRecord('tx_realty_districts');
		$district = tx_oelib_MapperRegistry::get('tx_realty_Mapper_District')
			->find($districtUid);

		$this->testingFramework->createRecord(
			'tx_realty_objects', array('district' => $districtUid)
		);
		$this->testingFramework->createRecord(
			'tx_realty_objects', array('district' => $districtUid)
		);

		$this->assertEquals(
			2,
			$this->fixture->countByDistrict($district)
		);
	}


	////////////////////////////////////////////////
	// Tests concerning the relation to the images
	////////////////////////////////////////////////

	/**
	 * @test
	 */
	public function imagesRelationFetchesImageModels() {
		$uid = $this->testingFramework->createRecord(
			'tx_realty_objects', array('images' => 1)
		);
		$this->testingFramework->createRecord(
			'tx_realty_images', array('object' => $uid)
		);

		$this->assertTrue(
			$this->fixture->find($uid)->getImages()->first()
				instanceof tx_realty_Model_Image
		);
	}


	////////////////////////////////////////////////
	// Tests concerning the relation to the images
	////////////////////////////////////////////////

	/**
	 * @test
	 */
	public function imagesRelationFetchesImageModels() {
		$uid = $this->testingFramework->createRecord(
			'tx_realty_objects', array('images' => 1)
		);
		$this->testingFramework->createRecord(
			'tx_realty_images', array('object' => $uid)
		);

		$this->assertTrue(
			$this->fixture->find($uid)->getImages()->first()
				instanceof tx_realty_Model_Image
		);
	}
}
?>