<?php

namespace SilbinaryWolf\SteamedClams;
use Debug;
use Folder;
use LogicException;
use DataObject;
use FieldList;
use Controller;

class ClamAVExtension extends \DataExtension {
	private static $has_many = array(
		'ClamAVScans' => 'SilbinaryWolf\\SteamedClams\\ClamAVScan',
	);

	/**
	 * @var ClamAVScan
	 */ 
	protected $_cache_scanForVirus = 0;

	/**
	 *
	 */
	//public function updateCMSFields(FieldList $fields) {
		// todo(Jake): Show 'ClamAVScans' on AssetAdmin/File level.
	//}

	/** 
	 * This is called within `File::write()` but before `File::onBeforeWrite()`.
	 *
	 * @return null
	 */
	public function validate(\ValidationResult $validationResult) {
		// If its a new file, scan it.
		$doVirusScan = ($this->owner->ID == 0);
	
		// Support VersionedFiles module
		// ie. If file has been replaced, scan it.
		$changeValue = defined('DataObject::CHANGE_VALUE') ? DataObject::CHANGE_VALUE : 1;
		$changedFields = $this->owner->getChangedFields(true, $changeValue);
		$currentVersionIDChanged = (isset($changedFields['CurrentVersionID'])) ? $changedFields['CurrentVersionID'] : array();
		if ($currentVersionIDChanged && $currentVersionIDChanged['before'] != $currentVersionIDChanged['after']) {
			$doVirusScan = true;
		}

		// NOTE(Jake): Perhaps add $this->extend('updateDoVirusScan'); so other modules can support this.

		// Skip scanning unless the *physical* file on disk/CDN/etc has changed
		if (!$doVirusScan) {
			return;
		}

		$record = $this->owner->scanForVirus();
		if (!$record) {
			return;
		}

		$denyOnFailure = ClamAV::config()->deny_on_failure;
		$denyUpload = ($record->IsInfected || ($denyOnFailure && !$record->IsScanned));
		// todo(Jake): Allow for custom deny rules with virus scan and TEST.
		//$this->owner->extend('updateDeny', $denyUpload, $record, $validationResult);

		if (!$denyUpload) {
			// Add the scan/log if the file is clean / allowed
			$this->owner->ClamAVScans()->add($record);
			return;
		}
		$validationResult->error(
			_t(
				'ClamAV.VIRUS_DETECTED', 
				'A virus was detected.'
			),
			'VIRUS'
		);

		// Delete infected file
		// (If file hasn't been written to DB yet)
		if ($this->owner->ID == 0) {
			$filepath = $this->owner->getFullPath();
			if (file_exists($filepath)) {
				@unlink($filepath);
			}
			$record->Action = ClamAVScan::ACTION_DELETED;
		}

		// Write log of infection to DB
		// (as this File record will never be written due to failing
		//  validation)
		if ($record && !$record->exists()) {
			$record->write();
		}
	}

	public function onAfterDelete() {
		foreach ($this->owner->ClamAVScans() as $scan) {
			$scan->processFileActionDelete();
		}
	}

	/**
	 * Whether the file can be scanned or not.
	 *
	 * @return boolean
	 */
	public function isVirusScannable() {
		if ($this->owner instanceof Folder) {
			return false;
		}
		// NOTE(Jake): Perhaps add $this->owner->extend() here? Maybe you want to avoid scanning
		//			   2GB files or similar? But maybe we want a different function that works
		//		 	   like ::validate(). Too early to say.
		return true;
	}

	/**
	 * Returns an unsaved `ClamAVScan` record with information regarding the virus scan
	 *
	 * @return ClamAVScan
	 */
	public function scanForVirus() {
		if (!$this->isVirusScannable()) {
			return null;
		}
		if ($this->_cache_scanForVirus !== 0) {
			return $this->_cache_scanForVirus;
		}
		$record = singleton('SilbinaryWolf\SteamedClams\ClamAV')->scanFileRecordForVirus($this->owner);
		return $this->_cache_scanForVirus = $record;
	}
}