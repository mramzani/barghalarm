<?php

namespace App\Services\Scraper;

use App\Models\Address;
use App\Models\Area;
use Symfony\Component\BrowserKit\HttpBrowser;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Component\DomCrawler\Crawler;


class OutageScraper
{
	private HttpBrowser $client;

	public function __construct()
	{
		$this->client = new HttpBrowser(
			HttpClient::create([
				'timeout' => 60,
				'max_duration' => 120,
				'headers' => [
					'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/139.0.0.0 Safari/537.36',
					'Accept' => '*/*',
					'Accept-Language' => 'en-US,en;q=0.9,fa;q=0.8,de;q=0.7',
				],
			])
		);
	}

	public function searchOutages(string $dateFrom, string $dateTo, string $areaValue = '3'): array
	{
		$crawler = $this->client->request('GET', 'https://khamooshi.maztozi.ir/');

		$form = $this->findSearchForm($crawler);

		$rb = $crawler->filter('input#ContentPlaceHolder1_rbIsAddress');
		$rbName = 'ctl00$ContentPlaceHolder1$rbIsAddress';
		$rbValue = 'on';
		if ($rb->count() > 0) {
			$rbName = $rb->attr('name') ?: $rbName;
			$rbValue = $rb->attr('value') ?: $rbValue;
		}

		$areaId = 'ContentPlaceHolder1_ddlArea';
		$areaName = $crawler->filter('#'.$areaId)->count() ? ($crawler->filter('#'.$areaId)->attr('name') ?: 'ctl00$ContentPlaceHolder1$ddlArea') : 'ctl00$ContentPlaceHolder1$ddlArea';
		$fromId = 'ContentPlaceHolder1_txtPDateFrom';
		$toId = 'ContentPlaceHolder1_txtPDateTo';
		$fromName = $crawler->filter('#'.$fromId)->count() ? ($crawler->filter('#'.$fromId)->attr('name') ?: 'ctl00$ContentPlaceHolder1$txtPDateFrom') : 'ctl00$ContentPlaceHolder1$txtPDateFrom';
		$toName = $crawler->filter('#'.$toId)->count() ? ($crawler->filter('#'.$toId)->attr('name') ?: 'ctl00$ContentPlaceHolder1$txtPDateTo') : 'ctl00$ContentPlaceHolder1$txtPDateTo';

		$values = [
			$rbName => $rbValue,
			$areaName => $areaValue,
			$fromName => $dateFrom,
			$toName => $dateTo,
		];

		$crawler = $this->client->submit($form, $values);

		return $this->parseResultsTable($crawler);
	}

	private function findSearchForm(Crawler $crawler)
	{
		$btn = $crawler->filter('input#ContentPlaceHolder1_btnSearchOutage');
		if ($btn->count() > 0) {
			return $btn->form();
		}
		$btn2 = $crawler->selectButton('جستجو');
		if ($btn2->count() > 0) {
			return $btn2->form();
		}
		throw new \RuntimeException('Search form not found.');
	}

	private function parseResultsTable(Crawler $crawler): array
	{
		$table = $crawler->filter('#ContentPlaceHolder1_grdOutages');
		$headers = [];
		$rows = [];
		if ($table->count() === 0) {
			return [$headers, $rows];
		}
		$table->filter('tr')->each(function (Crawler $tr) use (&$headers, &$rows) {
			$hasHeader = $tr->filter('th')->count() > 0;
			$cells = $tr->filter('th,td')->each(function (Crawler $td) {
				return trim(preg_replace('/\s+/', ' ', $td->text()));
			});
			if (empty($cells)) {
				return;
			}
			if ($hasHeader && empty($headers)) {
				$headers = $cells;
				return;
			}
			$rows[] = $cells;
		});
		return [$headers, $rows];
	}

	public function getAddressId(array $blackout, Area $area): ?int
	{
		if (!array_key_exists(4, $blackout)) {
            return null;
        }

        $addressText = trim((string) $blackout[4]);
        if ($addressText === '') {
            return null;
        }

        return Address::where('city_id', $area->city_id)
            ->where('address', $addressText)
            ->value('id');
		
	}

}


