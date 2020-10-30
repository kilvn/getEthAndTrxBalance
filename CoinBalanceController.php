<?php

namespace App\Http\Controllers\Api;

use Achse\GethJsonRpcPhpClient\JsonRpc as EthJsonRpc;
use IEXBase\TronAPI\Provider\HttpProvider as Tron;
use Illuminate\Http\Request;
use Kilvn\GethTokenJsonRpcPhpClient as EthTokenJsonRpc;

class CoinBalanceController extends ApiController
{
    protected int $decimals = 6;

    public function __construct(Request $request)
    {
        parent::__construct();

        $decimals = (int)$request->header('decimals', 6);
        // 全局默认的小数位数
        bcscale($decimals);
    }

    /**
     * 获取ETH钱包余额
     *
     * @param Request $request
     * @return mixed
     */
    public function getEthBalance(Request $request)
    {
        try {
            $address = $request->get('address', '');

            if (!isValidEthAddress($address)) {
                return $this->failed('请传入合法的ETH钱包地址', 201);
            }

            $httpClient = new EthJsonRpc\GuzzleClient(new EthJsonRpc\GuzzleClientFactory(), env('ETH_NODE_URL'), env('ETH_NODE_PORT'));
            $client = new EthJsonRpc\Client($httpClient);

            $result = $client->callMethod('eth_getBalance', [$address, 'latest']);

            if (isset($result->error)) {
                return $this->failed("error-{$result->error->code}: " . $result->error->message, 201);
            }

            if (!isset($result->error->code) && isset($result->result)) {
                $result = [
                    'hex' => $result->result,
                    'number' => WeiToEth(HexToDec($result->result)),
                ];
            }

            return $this->setStatusCode(200)->success($result);
        } catch (\Exception $exception) {
            return $this->putException($exception);
        }
    }

    /**
     * 获取ETH钱包代币余额
     *
     * @param Request $request
     * @return mixed
     * @throws \GuzzleHttp\Exception\GuzzleException
     */
    public function getEthTokenBalance(Request $request)
    {
        try {
            $contract_address = $request->get('contract_address');
            $address = $request->get('address');

            if (!isValidEthAddress($contract_address)) {
                return $this->failed('请传入合法的ETH合约地址', 201);
            }

            if (!isValidEthAddress($address)) {
                return $this->failed('请传入合法的ETH钱包地址', 201);
            }

            // 从缓存中获取该合约小数位数
            $contract_decimals_key = 'contract_decimals_' . $contract_address;
            $contract_decimals = \Cache::get($contract_decimals_key);
            if (!$contract_decimals) {
                $getTokenInfo = "http://api.ethplorer.io/getTokenInfo/{$contract_address}?apiKey=freekey";
                $client = new \GuzzleHttp\Client();
                $response = $client->get($getTokenInfo);

                $status = $response->getStatusCode();

                if ($status != 200) {
                    return $this->failed("合约小数位数获取失败", 201);
                }

                $token_info = $response->getBody()->getContents();
                $token_info = json_decode($token_info, JSON_OBJECT_AS_ARRAY);

                if (isset($token_info['decimals']) && $token_info['decimals'] >= 0) {
                    $contract_decimals = $token_info['decimals'];

                    //保存
                    \Cache::forever($contract_decimals_key, $contract_decimals);
                }
            }

            $wax = new EthTokenJsonRpc\Wax(env('ETH_NODE_URL'), env('ETH_NODE_PORT'));
            $wax->setContract($contract_address, $contract_decimals);

            $balance = $wax->getWaxBalance($address);

            $balance = EthTokenBalance($balance, $contract_decimals);
            $result = [
                'number' => $balance,
            ];

            return $this->setStatusCode(200)->success($result);
        } catch (\Exception $exception) {
            return $this->putException($exception);
        }
    }

    /**
     * 获取Trx钱包余额
     *
     * @param Request $request
     * @return mixed
     */
    public function getTrxBalance(Request $request)
    {
        try {
            $address = $request->get('address', '');

            $fullNode = new Tron(env('TRON_NODE'));
            $solidityNode = new Tron(env('TRON_NODE'));
            $eventServer = new Tron(env('TRON_NODE'));

            try {
                $tron = new \IEXBase\TronAPI\Tron($fullNode, $solidityNode, $eventServer);
            } catch (\IEXBase\TronAPI\Exception\TronException $e) {
                return $this->putException($e);
            }

            $valid_address = $tron->validateAddress($address);
            if (!$valid_address['result']) {
                return $this->failed('请传入合法的Trx钱包地址', 201);
            }

            // Balance
            $balance = $tron->getBalance($address, true);

            $result = [
                'number' => number_format($balance, $this->decimals, '.', ''),
            ];

            return $this->setStatusCode(200)->success($result);
        } catch (\Exception $exception) {
            return $this->putException($exception);
        }
    }

    /**
     * 获取Trx钱包代币余额
     *
     * @param Request $request
     * @return mixed
     */
    public function getTrxTokenBalance(Request $request)
    {
        try {
            $contract_address = $request->get('contract_address');
            $address = $request->get('address', '');

            $fullNode = new Tron(env('TRON_NODE'));
            $solidityNode = new Tron(env('TRON_NODE'));
            $eventServer = new Tron(env('TRON_NODE'));

            try {
                $tron = new \IEXBase\TronAPI\Tron($fullNode, $solidityNode, $eventServer);
            } catch (\IEXBase\TronAPI\Exception\TronException $e) {
                return $this->putException($e);
            }

            $valid_contract_address = $tron->validateAddress($contract_address);
            if (!$valid_contract_address['result']) {
                return $this->failed('请传入合法的Trx合约地址', 201);
            }

            $valid_address = $tron->validateAddress($address);
            if (!$valid_address['result']) {
                return $this->failed('请传入合法的Trx钱包地址', 201);
            }

            $client = new \GuzzleHttp\Client();

            // Token Balance
            $url = env('TRON_NODE') . '/wallet/triggerconstantcontract';
            $options['json'] = [
                'contract_address' => $tron->toHex($contract_address),
                'function_selector' => 'balanceOf(address)',
                'parameter' => str_pad('', 22, '0', STR_PAD_LEFT) . $tron->toHex($address),
                'owner_address' => $tron->toHex($address),
            ];
            $response = $client->post($url, $options);

            $status = $response->getStatusCode();

            if ($status != 200) {
                return $this->failed("Token余额获取失败", 201);
            }

            $token_info = $response->getBody()->getContents();
            $token_info = json_decode($token_info, JSON_OBJECT_AS_ARRAY);

            if (isset($result['result']['message'])) {
                return $this->putException(null, $tron->toUtf8($token_info['result']['message']));
            }

            if (!isset($token_info['result']['result']) or !isset($token_info['constant_result'][0])) {
                return $this->putException(null, 'Token余额获取失败');
            }

            $hex_balance = preg_replace('/^0+/', '', $token_info['constant_result'][0]);
            $balance = $tron->fromTron(hexdec($hex_balance));

            $result = [
                'number' => number_format($balance, $this->decimals, '.', ''),
            ];

            return $this->setStatusCode(200)->success($result);
        } catch (\Exception $exception) {
            return $this->putException($exception);
        }
    }
}
