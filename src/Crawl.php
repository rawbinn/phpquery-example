<?php

namespace Rawbinn\Crawl;

use phpQuery;

/**
 * Class Crawl.
 *
 * @author Rawbinn Shrestha <contact@rawbinn.com>
 */
class Crawl
{
    /**
     * Crawling init.
     *
     * @return void
     */
    public function init()
    {
        echo 'starting...'.date('H:i:s')."\n";
        $AreaOfPracticeId = [];
        echo "fetching parameters...\n";
        $response = $this->httpGet('https://www.otaus.com.au/find-an-ot');
        echo "fetching parameters completed...\n";
        phpQuery::newDocument($response);
        foreach (phpQuery::pq('#memberSearch_AreaOfPracticeId option') as $item) {
            $AreaOfPracticeId[] = phpQuery::pq($item)->val();
        }
        $totalAreaOfPracticeId = count($AreaOfPracticeId);

        echo "0% \n";
        foreach ($AreaOfPracticeId as $key => $aid) {
            $data = [
                    'ServiceType' => 2,
                    'State' => 'State',
                    'Distance' => 0,
                    'AreaOfPracticeId' => $aid,
                ];

            $url = 'https://www.otaus.com.au/search/membersearchdistance';
            echo 'fetching data...'.date('H:i:s')."\n";
            $response = $this->httpPost($url, $data);
            $response = json_decode($response, true);
            $membersId = $response['mainlist']; //List of members id
            $membersIdChunk = array_chunk($membersId, 40);
            foreach ($membersIdChunk as $members) {
                $uriQuery = 'ids='.implode('&ids=', $members);
                $uri = 'https://www.otaus.com.au/search/getcontacts?'.$uriQuery;
                $response = $this->httpGet($uri);
                $output = $this->getFormattedData($response);
                $this->storeAsCsv($output, 'members_info');
                echo '.';
            }
            echo "\n".round((($key + 1) / $totalAreaOfPracticeId) * 100)."%\n";
        }
        echo "fetching data completed...\n";
        echo 'ending...'.date('H:i:s');
    }

    /**
     * Returns formatted array of crawled content.
     *
     * @param $data Html data for phpQuery
     *
     * @return array
     */
    public function getFormattedData($data)
    {
        phpQuery::newDocument($data);
        $membersInfo = [];

        //loop through all result items
        foreach (phpQuery::pq('.results__item') as $key => $item) {
            $membersInfo[$key]['PracticeName'] = phpQuery::pq('.main-contact-content .title__tag:first', $item)->text();
            $membersInfo[$key]['ContactName'] = phpQuery::pq('.main-contact-content .name:first', $item)->text();

            $address = explode('<br>', phpQuery::pq('.main-contact-content p:nth-child(3)', $item)->html());
            $city = '';
            $street = '';
            $state = '';
            $country = '';
            if (count($address) > 2) {
                // if street data exist
                $street = trim($address[0]); //street data - index[0]
                $address_part = explode(',', $address[1]); //city, state, postal data - index[1]
                $city = isset($address_part[0]) ? trim($address_part[0]) : '';
                if (isset($address_part[1])) {
                    $state = !is_numeric(trim($address_part[1])) ? trim($address_part[1]) : '';
                }
                preg_match('/\d{4}/', $address[1], $postalCode);
                $country = preg_match('/[a-zA-Z]{2,}/', trim($address[2])) ? trim($address[2]) : ''; //country - index[2]
            } else {
                $address_part = explode(',', $address[0]);
                if (count($address_part) == 1) {
                    $street = trim($address_part[0]);
                } else {
                    $city = isset($address_part[0]) ? trim($address_part[0]) : '';
                    if (isset($address_part[1])) {
                        $state = !is_numeric(trim($address_part[1])) ? trim($address_part[1]) : '';
                    }
                    preg_match('/\d{4}/', $address[0], $postalCode); //for postal code
                }

                if (isset($address[1])) {
                    $address_part = explode(',', $address[1]);
                    if (count($address_part) == 1) {
                        $country = preg_match('/[a-zA-Z]{2,}/', trim($address[1])) ? trim($address[1]) : ''; //country - index[1]
                    } else {
                        $city = isset($address_part[0]) ? trim($address_part[0]) : '';
                        if (isset($address_part[1])) {
                            $state = !is_numeric(trim($address_part[1])) ? trim($address_part[1]) : '';
                        }
                        preg_match('/\d{4}/', $address[1], $postalCode); //for postal code
                    }
                }
            }
            $phoneNumber = str_replace(' ', '', trim(phpQuery::pq('.main-contact-content a[href^="tel:', $item)->text()));

            $membersInfo[$key]['AddressStreet'] = $street;
            $membersInfo[$key]['AddressCity'] = $city;
            $membersInfo[$key]['AddressState'] = $state;
            $membersInfo[$key]['AddressPostCode'] = $postalCode[0] ?? '';
            $membersInfo[$key]['AddressCountry'] = $country;
            $membersInfo[$key]['Phone'] = is_numeric($phoneNumber) ? $phoneNumber : '';

            $extraInfo = preg_replace("/\r|\n/", '', phpQuery::pq('.content__col:nth-child(2) p:first', $item)->text());

            preg_match('/(Funding Scheme\(s\)\:)(.*)(Area\(s\) of Practice\:)/', $extraInfo, $schemes);
            $membersInfo[$key]['FundingScheme'] = $schemes[2] ?? '';

            preg_match('/(Area\(s\) of Practice\:)(.*)$/', $extraInfo, $areas);
            $membersInfo[$key]['AreasOfPractice'] = $areas[2] ?? '';
        }

        return $membersInfo;
    }

    /**
     * Store data in csv format.
     *
     * @param $data     Array of data to store in csv
     * @param $filename Name of file to be saved
     *
     * @return void
     */
    public function storeAsCsv($data, $filename)
    {
        if (!file_exists('storage')) {
            mkdir('storage', 0777, true);
        }

        $filename = 'storage/'.$filename.'.csv';
        if (file_exists($filename)) {
            $file = fopen($filename, 'a');
        } else {
            $file = fopen($filename, 'w');
            $headers = ['PracticeName', 'ContactName', 'AddressStreet', 'AddressCity', 'AddressState', 'AddressPostCode', 'AddressCountry', 'Phone', 'FundingScheme', 'AreasOfPractice'];
            fputcsv($file, $headers);
        }
        foreach ($data as $memberInfo) {
            fputcsv($file, $memberInfo);
        }
        fclose($file);
    }

    /**
     * Http get request.
     *
     * @param $uri Url for http get request
     *
     * @return mixed
     */
    public function httpGet($uri)
    {
        try {
            $curl = curl_init();
            curl_setopt($curl, CURLOPT_URL, $uri);
            curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);

            //for debug only!
            curl_setopt($curl, CURLOPT_SSL_VERIFYHOST, false);
            curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, false);

            $response = curl_exec($curl);
            $info = curl_getinfo($curl);
            // echo 'GET API Response time: '.$info['total_time']."\n"; //uncomment to view response time
            curl_close($curl);
        } catch (\Exception $e) {
            error_log($e->getMessage(), 0);
        }

        return $response;
    }

    /**
     * Http post request.
     *
     * @param string $url  Http url for post request
     * @param array  $data Request body
     *
     * @return mixed
     */
    public function httpPost($url, $data)
    {
        try {
            $curl = curl_init($url);
            curl_setopt($curl, CURLOPT_URL, $url);
            curl_setopt($curl, CURLOPT_POST, true);
            curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);

            $headers = [
            'Content-Type: application/x-www-form-urlencoded',
            ];
            curl_setopt($curl, CURLOPT_HTTPHEADER, $headers);

            curl_setopt($curl, CURLOPT_POSTFIELDS, http_build_query($data));

            //for debug only!
            curl_setopt($curl, CURLOPT_SSL_VERIFYHOST, false);
            curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, false);

            $response = curl_exec($curl);
            $info = curl_getinfo($curl);
            curl_close($curl);

            // echo 'POST API Response time: '.$info['total_time']."\n"; //uncomment this line to view response time
        } catch (\Exception $e) {
            error_log($e->getMessage(), 0);
        }

        return $response;
    }
}
