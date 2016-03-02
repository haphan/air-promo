<?php

namespace AppBundle\Controller;

use PriceProviderBundle\Entity\Airport;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Route;
use Symfony\Bundle\FrameworkBundle\Controller\Controller;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\VarDumper\VarDumper;

class DefaultController extends Controller
{
    /**
     * @Route("/", name="homepage")
     */
    public function indexAction(Request $request)
    {
//        $crawler = $this->get('price_provider.crawler.vietjetair');
//        VarDumper::dump(
//            $crawler->crawl(new \DateTime('2016-04-10'), Airport::VIETNAM_TAN_SON_NHAT, Airport::VIETNAM_HA_NOI, 0, 0)
//        );
//        die;
    }
}
