<?php

namespace App\Services;

use Config;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Log;

use App\FormSubmissions;
use App\OdkOrgunit;
use App\OdkProject;
use DateInterval;
use DatePeriod;
use DateTime;
use Exception;
use Illuminate\Support\Arr;
use League\Csv\Reader;
use League\Csv\Statement;

use ZipArchive;

class ODkHTSDataAggregator
{
    private $reportSections = array();
    private $siteType = null;
    private $startDate = null;
    private $endDate = null;


    public function __construct()
    {
        $this->reportSections["agreement_rate"] = 1;
    }


    public function getData($orgUnitIds, $siteTypes, $startDate, $endDate)
    {


        $currentDate = new DateTime('now');

        $this->startDate = empty($startDate) ?  $currentDate->modify('-3 months')->format("Y-m-d") : $startDate;
        $this->endDate = empty($endDate) ? date("Y-m-d") : $endDate;

        $recordsReadData = [];
        $payload = null;
        if (isset($siteTypes) && !empty($siteTypes)) {
            $payload = array();
            for ($x = 0; $x < count($siteTypes); $x++) {
                $this->siteType = strtolower($siteTypes[$x]);
                [$recordsReadData, $payld] = $this->getDataLoopOrgs($orgUnitIds, $recordsReadData);
                for ($i = 0; $i < count($orgUnitIds); $i++) {
                    $payld[$orgUnitIds[$i]]["OrgUniType"] = $siteTypes[$x];
                }
                $payload[] = $payld;
            }
        } else {
            [$recordsReadData, $payld] = $this->getDataLoopOrgs($orgUnitIds, $recordsReadData);
            $payload = array();
            $payload[] = $payld;
        }

        $payload = $this->aggregateAgreementRates($payload);

        return $payload;
    }

    private function aggregateAgreementRates($payload)
    {

        $county['overall_concordance_totals'] = [];
        foreach ($payload as $payldkey => $payld) {
            foreach ($payld as $countykey => $county) {
                try {
                    foreach ($county['overall_agreement_rate'] as $monthlySiteskey => $monthlySites) {
                        $scores = array();
                        $scores['>98'] = 0;
                        $scores['95-98'] = 0;
                        $scores['<95'] = 0;
                        $scores['total_sites'] = 0;
                        $monthlySites['totals'] = $scores;
                        $monthlySites['concordance-totals'] = 0;

                        $monthlySites['concordance_t1_reactive'] = 0;
                        $monthlySites['concordance_t2_reactive'] = 0;

                        foreach ($monthlySites as $site) {
                            try {

                                $agreement = ($site['t2_reactive'] + $site['t1_non_reactive']) / ($site['t1_reactive'] + $site['t1_non_reactive']);
                                $monthlySites['totals']['total_sites'] += 1;
                                $agreementRate = $agreement * 100;

                                $monthlySites['concordance_t1_reactive'] += $site['t1_reactive'];
                                $monthlySites['concordance_t2_reactive'] += $site['t2_reactive'];

                                if ($agreementRate > 98) {
                                    $monthlySites['totals']['>98'] += 1;
                                } else if ($agreementRate >= 95 && $agreementRate <= 98) {
                                    $monthlySites['totals']['95-98'] += 1;
                                } else if ($agreementRate < 95) {
                                    $monthlySites['totals']['<95'] += 1;
                                }
                            } catch (Exception $ex) {
                            }
                        }
                        $totalConcordance = 0;
                        try {
                            $totalConcordance = ($monthlySites['concordance_t2_reactive'] * 100) / $monthlySites['concordance_t1_reactive'];
                            $totalConcordance=number_format((float)$totalConcordance, 1, '.', '');
                        } catch (Exception $ex) {
                        }

                        $county['overall_agreement_rate'][$monthlySiteskey] = []; // do not include per site scores in payload
                        $county['overall_agreement_rate'][$monthlySiteskey]['totals'] = $monthlySites['totals'];

                        $county['overall_concordance_totals'][$monthlySiteskey] = $totalConcordance;
                    }
                } catch (Exception $ex) {
                    Log::error($ex);
                }

                $payld[$countykey] = $county;
            }
            $payload[$payldkey] = $payld;
        }
        return $payload;
    }

    private function getDataLoopOrgs($orgUnitIds, $recordsReadData)
    {
        $payload = array();
        for ($x = 0; $x < count($orgUnitIds); $x++) {
            try {
                $odkUtils = new ODKUtils();
                $orgMeta = $odkUtils->getOrgsByLevel($orgUnitIds[$x]);
                $orgToProcess = $orgMeta[0];
                $level = $orgMeta[1];

                [$orgUnit,  $orgUnitName] = $odkUtils->getOrgUnitHierachyNames($orgToProcess, $level);

                $orgUnit['org_unit_id'] = $orgUnitIds[$x];

                $records = null;

                if (array_key_exists($orgUnit['org_unit_id'], $recordsReadData)) {
                    $records = $recordsReadData[$orgUnit['org_unit_id']];
                } else {
                    $records = $this->getFormRecords($orgUnit);
                    $recordsReadData[$orgUnit['org_unit_id']] = $records;
                }
                $results = array();
                $results["orgName"] = $orgUnitName;

                $results["overall_agreement_rate"] = $this->getOverallAgreementsRate($orgUnit, $records);

                $payload[$orgUnitIds[$x]] = $results;
            } catch (Exception $ex) {
                Log::error($ex);
            }
        }
        return [$recordsReadData, $payload];
    }

    private function sumValues($record, $monthScoreMap, $rowsPerMonthAndScoreCounter, $section)
    {

        $dateValue = strtotime($record['registerstartdate']);

        $yr = date("Y", $dateValue);
        $mon = date("m", $dateValue);
        $siteConcatName = $record['mysites_county'] . $record['mysites_subcounty'] . $record['mysites_facility'] . $record['mysites'];

        if (!array_key_exists($siteConcatName, $monthScoreMap[$yr . '-' . $mon])) {
            Log::info("initialized");
            $monthScoreMap[$yr . '-' . $mon][$siteConcatName] = array('t1_reactive' => 0, 't1_non_reactive' => 0, 't2_reactive' => 0);
        }
        $monthScoreMap[$yr . '-' . $mon][$siteConcatName]['t1_reactive'] += $record['Section-section0-testreactive'];
        $monthScoreMap[$yr . '-' . $mon][$siteConcatName]['t1_non_reactive'] += $record['Section-section0-nonreactive'];
        $monthScoreMap[$yr . '-' . $mon][$siteConcatName]['t2_reactive'] += $record['Section-section1-testreactive1'];

        $rowsPerMonthAndScoreCounter[$yr . '-' . $mon] += 1;

        return [$monthScoreMap, $rowsPerMonthAndScoreCounter];
    }

    private function processRecord($record, $monthScoreMap, $orgUnit, $rowsPerMonthAndScoreCounter, $rowCounter, $section)
    {
        // $record, $monthScoreMap, $orgUnit, $rowsPerMonthAndScoreCounter, $score, $rowCounter, $section

        if ($orgUnit['mysites_county'] == 'kenya' || empty($orgUnit['mysites_county'])) {
            Log::info("processing kenya");
            $rowCounter = $rowCounter + 1; //no or rows processed/mathced for an org unit or units below it.

            $valueAccumulations = $this->sumValues($record, $monthScoreMap, $rowsPerMonthAndScoreCounter, $section);
            $monthScoreMap = $valueAccumulations[0];
            $rowsPerMonthAndScoreCounter = $valueAccumulations[1];
            //$score =  $this->callFunctionBysecition($section, $record);
        } else {
            Log::info(strtolower($record['mysites_county']) . "  compp  " . $orgUnit['mysites_county']);
            if (strtolower($record['mysites_county']) == $orgUnit['mysites_county']) {
                Log::info("facility 1 " . $orgUnit['mysites_county']);
                if (!empty($orgUnit['mysites_subcounty'])) {
                    Log::info(strtolower($record['mysites_subcounty']) . " facility2 " . $orgUnit['mysites_subcounty']);
                    if (strtolower($record['mysites_subcounty']) == $orgUnit['mysites_subcounty']) {

                        if (!empty($orgUnit['mysites_facility'])) {
                            Log::info(strtolower($record['mysites_facility']) . " facility3 " . $orgUnit['mysites_facility']);
                            if (strtolower($record['mysites_facility']) == $orgUnit['mysites_facility']) {
                                Log::info(strtolower($record['mysites']) . " site1 " . $orgUnit['mysites']);
                                if (!empty($orgUnit['mysites'])) {
                                    Log::info(strtolower($record['mysites']) . " site2 " . $orgUnit['mysites']);
                                    if (strtolower($record['mysites']) == $orgUnit['mysites']) {
                                        Log::info(strtolower($record['mysites']) . " site3 " . $orgUnit['mysites']);
                                        $rowCounter = $rowCounter + 1; //no or rows processed/mathced for an org unit or units below it.

                                        $valueAccumulations = $this->sumValues($record, $monthScoreMap, $rowsPerMonthAndScoreCounter, $section);
                                        $monthScoreMap = $valueAccumulations[0];
                                        $rowsPerMonthAndScoreCounter = $valueAccumulations[1];
                                        // $score =  $this->callFunctionBysecition($section, $record) ;
                                    }
                                } else {
                                    $rowCounter = $rowCounter + 1; //no or rows processed/mathced for an org unit or units below it.

                                    $valueAccumulations = $this->sumValues($record, $monthScoreMap, $rowsPerMonthAndScoreCounter, $section);
                                    $monthScoreMap = $valueAccumulations[0];
                                    $rowsPerMonthAndScoreCounter = $valueAccumulations[1];
                                    // $score =  $this->callFunctionBysecition($section, $record)  + $score;
                                }
                            }
                        } else {
                            $rowCounter = $rowCounter + 1; //no or rows processed/mathced for an org unit or units below it.

                            $valueAccumulations = $this->sumValues($record, $monthScoreMap, $rowsPerMonthAndScoreCounter, $section);
                            $monthScoreMap = $valueAccumulations[0];
                            $rowsPerMonthAndScoreCounter = $valueAccumulations[1];
                            //$score =  $this->callFunctionBysecition($section, $record)  + $score;
                        }
                    }
                } else {
                    $rowCounter = $rowCounter + 1; //no or rows processed/mathced for an org unit or units below it.

                    $valueAccumulations = $this->sumValues($record, $monthScoreMap, $rowsPerMonthAndScoreCounter, $section);
                    $monthScoreMap = $valueAccumulations[0];
                    $rowsPerMonthAndScoreCounter = $valueAccumulations[1];
                    //$score =  $this->callFunctionBysecition($section, $record)  + $score;
                }
            }
        }

        return [$record, $monthScoreMap, $orgUnit, $rowsPerMonthAndScoreCounter, $rowCounter, $section];
    }

    private function getSummationValues($records, $orgUnit, $section)
    {
        $rowCounter = 0; //total rows passed through

        $monthScoreMap = []; //summation
        $rowsPerMonthAndScoreCounter = [];

        $startDate = date_create($this->startDate)->modify('first day of this month');

        $endDate = date_create($this->endDate)->modify('first day of next month');


        $interval = DateInterval::createFromDateString('1 month');
        $period   = new DatePeriod($startDate, $interval, $endDate);

        foreach ($period as $dt) {
            $monthScoreMap[$dt->format("Y-m")] = array();
            $monthScoreMap[$dt->format("Y-m")] = array();
            $monthScoreMap[$dt->format("Y-m")] = array();

            $rowsPerMonthAndScoreCounter[$dt->format("Y-m")] = 0;
        }

        foreach ($records as $record) {
            $shouldProcessRecord = true;

            $recordDate = strtotime($record['registerstartdate']);
            $newRecordformat = date('Y-m-d', $recordDate);

            $userStartDate = strtotime($this->startDate);
            $newUserStartDate = date('Y-m-d', $userStartDate);

            $userEndDate = strtotime($this->endDate);
            $newUserEndDate = date('Y-m-d', $userEndDate);

            if ($newUserStartDate > $newRecordformat ||  $newRecordformat > $newUserEndDate) {

                $shouldProcessRecord = false;
            }


            if (
                (isset($this->siteType) && substr(trim(strtolower($record['mysites'])), 0, strlen($this->siteType)) != $this->siteType)
            ) {
                $shouldProcessRecord = false;
            }

            if ($shouldProcessRecord) {
                [$record, $monthScoreMap, $orgUnit, $rowsPerMonthAndScoreCounter, $rowCounter, $section] =
                    $this->processRecord($record, $monthScoreMap, $orgUnit, $rowsPerMonthAndScoreCounter, $rowCounter, $section);
            }
        }

        $results = array();

        $results['rowsPerMonthAndScoreCounter'] = $rowsPerMonthAndScoreCounter;
        $results['monthScoreMap'] = $monthScoreMap;
        return $results;
    }

    private function getFormRecords($orgUnit)
    {

        $levelObj = OdkOrgunit::select("level")->where('org_unit_id', $orgUnit['org_unit_id'])->first();
        $level = $levelObj->level;
        $fileName = null;

        if ($level == 1) {
            $combinedRecords = [];
            $submissionOrgUnitmap = FormSubmissions::select("project_id", "form_id")
                ->where('form_id', 'like', "hts%") // for spi data
                ->get();
            foreach ($submissionOrgUnitmap as $mapping) {
                $projectId = $mapping->project_id;
                $formId = $mapping->form_id;
                $fileName = $this->getFileToProcess($projectId, $formId);
                $perCountyRecords = $this->getSingleFileRecords($fileName, $formId);
                if ($perCountyRecords) {
                    $combinedRecords = array_merge($combinedRecords, $perCountyRecords);
                }
            }
            return $combinedRecords;
        } else if ($level == 2) { // Form Submissions table maps orgid at county level to form id

            $odkUtils = new ODKUtils();
            [$projectId, $formId] = $odkUtils->getFormFormdProjectIds($orgUnit, "hts%");
            $fileName = $this->getFileToProcess($projectId, $formId);
            return $this->getSingleFileRecords($fileName, $formId);
        } else {

            $odkUtils = new ODKUtils();
            [$projectId, $formId] = $odkUtils->getFormFormdProjectIds($orgUnit, "hts%");
            $fileName = $this->getFileToProcess($projectId, $formId);
            return $this->getSingleFileRecords($fileName, $formId);
        }
    }

    private function getSingleFileRecords($file, $formId)
    {

        $url = '';
        if (Storage::exists($file)) {
            $url = Storage::path($file);
        }

        $zip = new ZipArchive;
        try {
            $zip->open($url);
            // Unzip Path
            $zip->extractTo('/tmp/');
            $zip->close();

            $csv = Reader::createFromPath('/tmp/' . $formId . '.csv', 'r');
            $csv->setHeaderOffset(0); //set the CSV header offset
            $stmt = Statement::create();
            $records = $stmt->process($csv);

            $csv = Reader::createFromPath('/tmp/' . $formId . '-pagerepeat.csv', 'r');
            $csv->setHeaderOffset(0); //set the CSV header offset
            $stmt = Statement::create();
            $recordsRepeat = $stmt->process($csv);

            $keyArray = array();

            foreach ($records as $record) {
                $keyArray[$record['KEY']] = $record;
            }

            $combinedRecords = [];
            foreach ($recordsRepeat as $record) {
                $combinedRecords[] = array_merge($keyArray[$record['PARENT_KEY']], $record);
            }

            return $combinedRecords;
        } catch (Exception $ex) {
            Log::error("could not open " . $file . ' ' . $formId);
            return [];
        }
    }

    private function callFunctionBysecition($section, $record, $overallSites = 0)
    {
        // if ($section == $this->reportSections["agreement_rate"]) {
        //     return $this->aggregatePersonnellAndTrainingScore($record);
        // }
    }

    //section 1 (agreement_rate)
    private function getOverallAgreementsRate($orgUnit, $records)
    {

        $summationValues = $this->getSummationValues($records, $orgUnit, $this->reportSections["agreement_rate"]);
        $monthScoreMap = $summationValues['monthScoreMap'];
        $rowsPerMonthAndScoreCounter = $summationValues['rowsPerMonthAndScoreCounter'];
        // $score = $this->getPercentileValueForSections($score, $rowCounter, 3);
        // $score = ($score / ($rowCounter * 3)) * 100; //get denominator   
        // $score = number_format((float)$score, 1, '.', ',');

        return $monthScoreMap;
    }

    private function getFileToProcess($projectId, $formId)
    {
        $filePath = "submissions/" . $projectId . "_" . $formId . "_submissions.csv.zip";
        return $filePath;
    }
}
