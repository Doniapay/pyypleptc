<?php

namespace App\Http\Controllers\Gateway\Doniapay;

use App\Models\Deposit;
use App\Http\Controllers\Gateway\PaymentController;
use App\Http\Controllers\Controller;

class ProcessController extends Controller
{
    public static function process($deposit)
    {
        $donia = json_decode($deposit->gatewayCurrency()->gateway_parameter);
        $invoice_id = $deposit->trx;
        $api_key = $donia->api_key;

        $raw_data = [
            "dn_su"  => route('ipn.' . $deposit->gateway->alias) . "?inv=" . $invoice_id,
            "dn_cu"  => route(gatewayRedirectUrl()),
            "dn_wu"  => route('ipn.' . $deposit->gateway->alias) . "?inv=" . $invoice_id,
            "dn_am"  => number_format($deposit->final_amo, 2, '.', ''),
            "dn_cn"  => $deposit->user->firstname ?? 'Customer',
            "dn_ce"  => $deposit->user->email ?? 'customer@email.com',
            "dn_mt"  => json_encode([
                "trx"   => $invoice_id,
                "phone" => $deposit->user->mobile ?? ''
            ]),
            "dn_rt"  => "GET"
        ];

        $payload   = base64_encode(json_encode($raw_data));
        $signature = hash_hmac('sha256', $payload, $api_key);

        $headers = [
            "X-Signature-Key: " . $api_key,
            "donia-signature: " . $signature,
            "Content-Type: application/json"
        ];

        $data = [
            'dp_payload' => $payload
        ];

        $response = self::curlPost('https://api.doniapay.com/v2/order/synchronize/prepare', $data, $headers);
        $res = json_decode($response, true);

        if (isset($res['status']) && ($res['status'] === 'success' || $res['status'] == 1) && !empty($res['payment_url'])) {
            header('Location: ' . $res['payment_url']);
            exit();
        } else {
            $message = $res['message'] ?? 'Payment initialization failed. Please contact support.';
            return response()->json([
                'error' => true,
                'message' => $message
            ]);
        }
    }

    public function ipn()
    {
        $track = request()->query('inv');
        $deposit = Deposit::where('trx', $track)->orderBy('id', 'DESC')->firstOrFail();

        $donia = json_decode($deposit->gatewayCurrency()->gateway_parameter);
        $api_key = $donia->api_key;

        $headers = [
            "X-Signature-Key: " . $api_key,
            "Content-Type: application/json"
        ];

        $transactionId = request()->query('transaction_id') ?? request()->query('transactionId');

        $data = [
            "transaction_id" => $transactionId
        ];

        $response = self::curlPost('https://api.doniapay.com/v2/order/synchronize/confirm', $data, $headers);
        $res = json_decode($response, true);

        if (isset($res['status']) && (strtolower($res['status']) === 'completed' || strtolower($res['status']) === 'success' || $res['status'] == 1)) {
            PaymentController::userDataUpdate($deposit->trx);
            $notify[] = ['success', 'Transaction was successful.'];
            return redirect()->route(gatewayRedirectUrl(true))->withNotify($notify);
        }

        session()->forget('deposit_id');
        session()->forget('payment_id');

        $notify[] = ['error', 'Invalid request.'];
        return redirect()->route(gatewayRedirectUrl())->withNotify($notify);
    }

    protected static function curlPost($url, $data, $headers)
    {
        $curl = curl_init();

        curl_setopt_array($curl, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_ENCODING => '',
            CURLOPT_MAXREDIRS => 10,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
            CURLOPT_CUSTOMREQUEST => 'POST',
            CURLOPT_POSTFIELDS => json_encode($data),
            CURLOPT_HTTPHEADER => $headers,
        ]);

        $response = curl_exec($curl);

        if (curl_errno($curl)) {
            $error = curl_error($curl);
            curl_close($curl);
            return json_encode(['status' => 'error', 'message' => $error]);
        }

        curl_close($curl);
        return $response;
    }
}
