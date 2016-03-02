<?php

namespace PriceProviderBundle\Services;

use GuzzleHttp\Client;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use Psr\Log\LoggerInterface;
use Symfony\Component\VarDumper\VarDumper;

class VietJetAir
{
    const API_ENDPOINT = 'https://m.vietjetair.com/mappv1/get-flight2.php';
    const VJ_MAX_BEFORE = 7;
    const VJ_MAX_AFTER = 7;
    const ERROR_RETRY = 5;

    private $headers = [
        'Origin'           => 'file://',
        'Content-Type'     => 'application/x-www-form-urlencoded;',
        'Accept'           => 'application/json, text/javascript, */*; ',
        'User-Agent'       => 'Mozilla/5.0 (iPhone; CPU iPhone OS 9_0_2 like Mac OS X) AppleWebKit/601.1.46 (KHTML, like Gecko) Mobile/13A452 (5181747616)',
        'Connection'       => 'keep-alive',
        'Proxy-Connection' => 'keep-alive',
        'Accept-Language'  => 'en-SG;',
        'Proxy-Connection' => 'keep-alive',
    ];

    private $defaultOptions = [
        'DaysBefore'   => '0',
        'DaysAfter'    => '0',
        'AdultCount'   => '1',
        'ChildCount'   => '0',
        'InfantCount'  => '0',
        'CurrencyCode' => 'VND',
        'PromoCode'    => ''
    ];

    /**@var Client */
    private $guzzle = null;

    private $logger;

    public function __construct(LoggerInterface $logger)
    {
        $this->logger = $logger;
    }

    private function init()
    {
        if (!$this->guzzle) {
            $this->guzzle = new Client([
                'headers' => $this->headers
            ]);
        }
    }

    private function crawl(
        \Datetime $outboundDate,
        $departureAirport,
        $arrivalAirport,
        $daysBefore = 0,
        $daysAfter = 0,
        $adultCount = 1,
        $currentcyCode = 'VND'
    )
    {
        $options = [
            'OutboundDate'         => $outboundDate->format('Y-m-d'),
            'DepartureAirportCode' => $departureAirport,
            'ArrivalAirportCode'   => $arrivalAirport,
            'DaysBefore'           => ($daysBefore > 7) ? max($daysBefore, 7) : $daysBefore,
            'DaysAfter'            => ($daysAfter > 7) ? max($daysAfter, 7) : $daysAfter,
            'AdultCount'           => $adultCount,
            'CurrencyCode'         => $currentcyCode
        ];

        $options = array_merge($this->defaultOptions, $options);

        $this->init();

        $retry = 0;
        do {
            $retry++;
            try {
                $response = $this->guzzle->post(self::API_ENDPOINT, [
                    'form_params' => $options
                ]);

                if ($response->getStatusCode() == '200') {
                    return json_decode($response->getBody()->getContents(), true);
                }
            } catch (\Exception $error) {
                $this->logger->error($error->getMessage());
            }
        } while ($retry <= self::ERROR_RETRY);
    }

    private function getChunks(\Datetime $start, \Datetime $end, $maxBefore, $maxAfter)
    {
        if ($end < $start) {
            throw new \Exception('End date must be later then start date');
        }

        $days = $end->diff($start)->days;

        $chunkLength = $maxBefore + $maxAfter;

        $chunkCount = (int)($days / $chunkLength);
        $chunkSpare = (int)($days % $chunkLength);

        $chunks = [];

        $chunkEnd = clone $end;

        for ($i = 0; $i < $chunkCount; $i++) {
            $chunkStart = clone $chunkEnd;
            $chunkStart->sub(\DateInterval::createFromDateString(sprintf('%s days', $chunkLength)));

            $chunkMid = clone $chunkEnd;
            $chunkMid->sub(\DateInterval::createFromDateString(sprintf('%s days', $maxAfter)));

            $chunks[] = ['start' => clone $chunkStart, 'mid' => clone $chunkMid, 'end' => clone $chunkEnd];

            //reset chunk end
            $chunkEnd = $chunkStart;
            $chunkEnd->sub(\DateInterval::createFromDateString('1 days'));

        }
        //add final chunk

        if ($chunkSpare > 0 && $chunkEnd >= $start) {
            $chunks[] = ['start' => clone $start, 'end' => clone $chunkEnd];
        }

        return $chunks;
    }

    public function findFare(\DateTime $startDate, \DateTime $endDate, $departureAirport, $arrivalAirport)
    {
        $periods = $this->getChunks($startDate, $endDate, self::VJ_MAX_BEFORE, self::VJ_MAX_AFTER);

        $buckets = [];


        foreach ($periods as $p) {
            $start = $p['start'];
            $end = $p['end'];

            $this->logger->debug(sprintf('Looking for deals from %s till %s', $start->format('d/m/Y'), $end->format('d/m/Y')), ['from' => $departureAirport, 'to' => $arrivalAirport]);

            if (!isset($p['mid'])) {
                array_push($buckets, $this->crawl($start, $departureAirport, $arrivalAirport, 0, $end->diff($start)->days));

            } else {
                $mid = $p['mid'];
                array_push($buckets, $this->crawl($mid, $departureAirport, $arrivalAirport, self::VJ_MAX_BEFORE, self::VJ_MAX_AFTER));
            }
        }


        return $this->extractFare($buckets);
    }

    public function findPromoFare(\DateTime $startDate, \DateTime $endDate, $departureAirport, $arrivalAirport)
    {
        $fares = $this->findFare($startDate, $endDate, $departureAirport, $arrivalAirport);

        $mapPromoFare = function ($fareOpt) {
            $codes = array_unique(array_map(function ($f) {
                return $f['Description'];
            }, $fareOpt['Fares']));

            if (count($codes) > 2) {
                //Filter out only Promo fares
                $fareOpt['Fares'] = array_filter($fareOpt['Fares'], function($f){ return $f['Description'] == 'Promo';});
                return $fareOpt;
            }

            return null;
        };
        return array_filter(array_map($mapPromoFare, $fares));
    }

    private function extractFare($buckets)
    {
        $fares = [];

        foreach ($buckets as $bucket) {
            $options = $bucket['GetTravelOptionsResult']['TravelOptions']['OutboundOptions']['Option'];

            $mapOption = function ($opt) {
                $keys = ['DiscountFare', 'Description', 'Sale', 'SeatsAvailable'];
                $mapFare = function ($fareOpts) use ($keys) {
                    return array_intersect_key($fareOpts, array_flip($keys));
                };

                $flight = $opt['Legs']['LegOption']['SegmentOptions']['SegmentOption']['Flight'];

                return [
                    //'Flight' => [],
                    'Fares' => array_map($mapFare, $opt['Legs']['LegOption']['FareOptions']['Adultfares']['FareOption']),
                    'DepartureDate' => $opt['Legs']['LegOption']['DepartureDate']

                ];
            };

            if(is_array($options))
            {
                $fares = array_merge($fares, array_map($mapOption, $options));
            }else{
                VarDumper::dump($options);
            }
        }

        return $fares;
    }
}