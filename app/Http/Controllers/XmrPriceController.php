<?php

/*
 | *--------------------------------------------------------------------------
 | Copyright Notice
 |--------------------------------------------------------------------------
 | Updated for Laravel 13.4.0 by AnonymousUser9183 / The Erebus Development Team.
 | Original Kabus Marketplace Script created by Sukunetsiz.
 |--------------------------------------------------------------------------
 */

namespace App\Http\Controllers;

use Exception;
use GuzzleHttp\Client;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;

class XmrPriceController extends Controller
{
    /**
     * Return the current XMR price in USD.
     */
    public function __invoke(): JsonResponse
    {
        $price = $this->fetchXmrPriceUsd();

        return response()->json([
            'currency' => 'XMR',
            'vs_currency' => 'USD',
            'price' => $price,
        ]);
    }

    /**
     * Fetch the current XMR price in USD using Tor-backed requests.
     */
    private function fetchXmrPriceUsd(): string
    {
        $client = new Client([
            'proxy' => [
                'http' => 'socks5h://127.0.0.1:9050',
                'https' => 'socks5h://127.0.0.1:9050',
            ],
            'timeout' => 30,
            'connect_timeout' => 15,
            'verify' => true,
            'curl' => [
                CURLOPT_PROXYTYPE => CURLPROXY_SOCKS5_HOSTNAME,
            ],
        ]);

        try {
            $response = $client->request('GET', 'https://api.coingecko.com/api/v3/simple/price', [
                'query' => [
                    'ids' => 'monero',
                    'vs_currencies' => 'usd',
                ],
                'timeout' => 25,
            ]);

            $data = json_decode((string) $response->getBody(), true);

            if (isset($data['monero']['usd']) && is_numeric($data['monero']['usd'])) {
                return number_format((float) $data['monero']['usd'], 2, '.', '');
            }
        } catch (Exception $exception) {
            Log::warning('CoinGecko XMR price lookup failed via Tor.', [
                'message' => $exception->getMessage(),
            ]);
        }

        try {
            $response = $client->request('GET', 'https://min-api.cryptocompare.com/data/price', [
                'query' => [
                    'fsym' => 'XMR',
                    'tsyms' => 'USD',
                ],
                'timeout' => 25,
            ]);

            $data = json_decode((string) $response->getBody(), true);

            if (isset($data['USD']) && is_numeric($data['USD'])) {
                return number_format((float) $data['USD'], 2, '.', '');
            }
        } catch (Exception $exception) {
            Log::warning('CryptoCompare XMR price lookup failed via Tor.', [
                'message' => $exception->getMessage(),
            ]);
        }

        Log::error('All XMR price APIs failed via Tor.');

        return 'UNAVAILABLE';
    }
}
