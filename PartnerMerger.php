<?php

class PartnerMerger {
	
	private $existing_partners;
	private $parsed_partners;
	private $updated_existing_partners = array();
	private $all_compare_count;
	private $compare_progress = 0;
	private $all_parsed_partner_save_count;
	private $parsed_partner_save_progress = 0;
	private $timer;
	private $status_id;
	
	public function __construct($existing_partners, $parsed_partners, $status_id) {
		$this->existing_partners = $existing_partners;
		$this->parsed_partners = $parsed_partners;
		$this->status_id = $status_id;
		$this->all_compare_count = count($existing_partners) * count($parsed_partners);
		$this->all_parsed_partner_save_count = count($parsed_partners);
	}
	
	public function getMergedPartners() {
		$this->merge();
		return $this->parsed_partners;
	}
	
	private function merge() {
		$this->resetTimer();
		foreach ($this->existing_partners as $existing_partner) {
			foreach ($this->parsed_partners as $parsed_partner) {
				if ($this->areTheSamePartner($existing_partner, $parsed_partner)) {
					$this->mergePartners($existing_partner, $parsed_partner);
				}
				$this->handlePercent();
				$this->compare_progress++;
			}
		}
		$this->updateExistingPartners();
		$this->saveParsedPartners();
	}
	
	private function areTheSamePartner($existing_partner, $parsed_partner) {
		$same_email = $parsed_partner['email'] != '' && $parsed_partner['email'] == $existing_partner[PartnerObj::_EMAIL] ;
		$country_code_id = isset($parsed_partner['phone_country_code_id']) ? $parsed_partner['phone_country_code_id'] : '';
		$parsed_phone = $country_code_id . $parsed_partner['phone'];
		$existing_phone = $existing_partner[PartnerObj::_PHONE_COUNTRY_CODE_ID] . $existing_partner[PartnerObj::_PHONE];
		$same_phone = !empty($parsed_phone) && $parsed_phone == $existing_phone;
		return $same_email || $same_phone;
	}
	
	private function mergePartners($existing_partner, $parsed_partner) {
		if ($this->dataChanged($existing_partner, $parsed_partner)) {
			$existing_partner[PartnerObj::_EMAIL] = $parsed_partner['email'];
			$existing_partner[PartnerObj::_PHONE] = $parsed_partner['phone'];
			$existing_partner[PartnerObj::_PHONE_COUNTRY_CODE_ID] = empty($parsed_partner['phone_country_code_id']) ? 0 : $parsed_partner['phone_country_code_id'];
			$merged_partner = Lib::arrayDeepMerge($existing_partner, $parsed_partner);
			$this->updated_existing_partners[$merged_partner[PartnerObj::_ID]] = $merged_partner;
			$this->parsed_partners[$parsed_partner['index']] = $merged_partner;
		} else {
			$this->parsed_partners[$parsed_partner['index']] = $existing_partner;
		}
	}
	
	private function updateExistingPartners() {
		foreach ($this->updated_existing_partners as $existing_partner) {
			$partner = new PartnerObj();
			$partner->setLoaded();
			$partner->setValues(array_map(array('SQL', 'escape'), $existing_partner));
			$partner->save();
		}
	}
	
	private function saveParsedPartners() {
		$this->all_parsed_partner_save_count = count($this->parsed_partners);
		foreach ($this->parsed_partners as $index => $parsed_partner) {
			if ($this->isParsedPartner($parsed_partner)) {
				$partner_obj = new PartnerObj();
				$partner_obj->setValues(array_map(array('SQL', 'escape'), $parsed_partner));
				$partner_obj->save();
				$this->parsed_partners[$index][PartnerObj::_ID] = $partner_obj->getId();
			}
			$this->handlePercent();
			$this->parsed_partner_save_progress++;
		}
	}
	
	private function getSqlFormOfPartnerForInsert($partner) {
		$fields = array(
			'NULL',
			Application::getAuthUser()->getUserId(),
			"'$partner[first_name]'",
			"'$partner[last_name]'",
			"'$partner[email]'",
			isset($partner['phone_country_code_id']) ? $partner['phone_country_code_id'] : 0,
			"'$partner[phone]'",
			"'$partner[birth_date]'",
			"'$partner[company_name]'",
			"'$partner[address]'",
			PartnerObj::STATUS_ACTIVE
		);
		
		return '(' . implode(',', $fields) . ')';
	}
	
	private function isParsedPartner($partner) {
		return !isset($partner[PartnerObj::_ID]);
	}
	
	private function resetTimer() {
		$this->timer = time();
	}
	
	private function updatePercent($percent) {
		SQL::query(
			'UPDATE partner_uploaded_csv SET percent = ' . ($percent == 100 ? 99 : $percent) . ' WHERE id = ' . $this->status_id
		);
	}
	
	private function timerHasExpired() {
		return $this->timer + 2 < time();
	}
	
	private function handlePercent() {
		if ($this->timerHasExpired()) {
			$compare_progress = $this->all_compare_count == 0 ? 1 : ($this->compare_progress / $this->all_compare_count);
			$save_progress = $this->parsed_partner_save_progress / $this->all_parsed_partner_save_count;
			$this->updatePercent(floor(($compare_progress + $save_progress) / 2 * 100));
			$this->resetTimer();
		}
	}
	
	private function dataChanged($existing_partner, $parsed_partner) {
		$changed = false;
		foreach ($parsed_partner as $field => $value) {
			if ($field != PartnerObj::_STATUS && isset($existing_partner[$field]) && $existing_partner[$field] != $value) {
				$changed = true;
				break;
			}
		}
		return $changed;
	}
}
