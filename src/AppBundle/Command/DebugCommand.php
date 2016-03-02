<?php

namespace AppBundle\Command;

use PriceProviderBundle\Entity\Airport;
use Symfony\Bundle\FrameworkBundle\Command\ContainerAwareCommand;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Helper\TableCell;
use Symfony\Component\Console\Helper\TableSeparator;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\VarDumper\VarDumper;

class DebugCommand extends ContainerAwareCommand
{
    private $output;

    protected function configure()
    {
        $this
            ->setName('apm:vja');
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $this->output = $output;

        $start = new \DateTime('2016-03-15');
        $end = clone $start;
        $end->add(\DateInterval::createFromDateString('150 days'));

//        $set[] = [Airport::SINGAPORE_CHANGI, Airport::VIETNAM_TAN_SON_NHAT];
//        $set[] = [Airport::VIETNAM_TAN_SON_NHAT, Airport::SINGAPORE_CHANGI];

//        $set[] = [Airport::VIETNAM_TAN_SON_NHAT, Airport::VIETNAM_NHA_TRANG];
//        $set[] = [Airport::VIETNAM_NHA_TRANG, Airport::VIETNAM_TAN_SON_NHAT];
//
//        $set[] = [Airport::VIETNAM_TAN_SON_NHAT, Airport::VIETNAM_PHU_QUOC];
//        $set[] = [Airport::VIETNAM_PHU_QUOC, Airport::VIETNAM_TAN_SON_NHAT];

//        $set[] = [Airport::VIETNAM_TAN_SON_NHAT, Airport::VIETNAM_HA_NOI];
//        $set[] = [Airport::VIETNAM_HA_NOI, Airport::VIETNAM_TAN_SON_NHAT];
        $set[] = [Airport::VIETNAM_TAN_SON_NHAT, Airport::THAILAND_BANGKOK_SUVARNABHUMI];
        $set[] = [Airport::THAILAND_BANGKOK_SUVARNABHUMI, Airport::VIETNAM_TAN_SON_NHAT];


        while (true) {
            foreach ($set as list($departureAirport, $arrivalAirport)) {
                $this->findPromo($start, $end, $departureAirport, $arrivalAirport);
            }
            sleep(60*50);
        }
    }

    private function findPromo(\Datetime $start, \Datetime $end, $departureAirport, $arrivalAirport)
    {
        $crawler = $this->getContainer()->get('price_provider.crawler.vietjetair');
        $logger = $this->getContainer()->get('logger');


        $logger->info(sprintf('Looking for deals from %s till %s', $start->format('d/m/Y'), $end->format('d/m/Y')), ['from' => $departureAirport, 'to' => $arrivalAirport]);

        $promo = $crawler->findPromoFare($start, $end, $departureAirport, $arrivalAirport);

        if (!empty($promo)) {
            $message = sprintf('Promo code found %s -> %s', $departureAirport, $arrivalAirport);
            $logger->emergency($message);
            $this->printPromoTicket($departureAirport, $arrivalAirport, $promo);
        }
    }

    private function printPromoTicket($departureAirport, $arrivalAirport, $data)
    {
        $transformData = function($row){
            try {
                return [
                    $row['DepartureDate'],
                    $row['Fares'][0]['Description'],
                    $row['Fares'][0]['DiscountFare'],
                    $row['Fares'][0]['SeatsAvailable']
                ];
            }catch(\Exception $e)
            {
                return [];
            }
        };


        $data = array_map($transformData, array_values($data));

        $table = new Table($this->output);


        $table
            ->setHeaders(array('Date', 'Type', 'Price','Seats'))
            ->addRow(array(new TableCell(sprintf('From %s -> %s', $departureAirport, $arrivalAirport), array('colspan' => 4))))
            ->addRow(new TableSeparator())
            ->addRows($data)
            ->render();
    }
}