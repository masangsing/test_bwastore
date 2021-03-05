<?php

namespace App\Http\Controllers;

use App\Cart;
use Exception;
use Midtrans\Snap;                                                                                                                                                                       
use Midtrans\Config;
use App\TransactionDetail;
use App\transaction;
use Midtrans\Notification;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;




class CheckoutController extends Controller
{
    public function process(Request $request)
    {
        // TODO: Save users data
        $user =Auth::user();
        $user->update($request->except('total_price'));

        // Proses checkout
        $code = 'STORE-' . mt_rand(00000,99999);
        $carts = Cart::with(['product','user'])
                    ->where('users_id', $user->id)
                    ->get();

        $transaction = Transaction::create([
            'users_id' => Auth::user()->id,
            'insurance_price' => 0,
            'shipping_price' => 0,
            'total_price' => $request->total_price,
            'transaction_status' => 'PENDING',
            'code' => $code,
            
        ]);

        foreach ($carts as $cart) {
            $trx = 'TRX-' . mt_rand(0000,9999);

            TransactionDetail::create([
                'transactions_id' => $transaction->id,
                'products_id' => $cart->product->id,
                'price' => $cart->product->price,
                'shipping_status' => 'PENDING',
                'resi' => '',
                'code' => $trx
            ]);
        }

        // Delete cart data
        Cart::where('users_id', Auth::user()->id)
                ->delete();

        // Konfigurasi midtrans
        Config::$serverKey = config('services.midtrans.serverKey');
        Config::$isproduction = config('services.midtrans.isproduction');
        Config::$isSanitized = config('services.midtrans.isSanitized');
        Config::$is3ds = config('services.midtrans.is3ds');

        // Buat array untuk dikirim ke midtrans
        $midtrans = [
            "transactions_details" => [
                "order_id" => $code,
                "gross_amount" => (int) $request->total_price,
            ],
            "customer_details" => [
                "first_name" => Auth::user()->name,
                "email" => Auth::user()->email,
            ],
            "enabled_payments" => [
                "gopay", "bank_transfer"
            ],
            "vtweb" => []
        ];
        try {
            // Ambil halaman payment midtrans
            $paymentUrl = Snap::createTransaction($midtrans)->redirect_url;

            // Redirect ke halaman midtrans
            return redirect($paymentUrl);
        }
        catch (Exception $e) {
            echo $e->getMessage();
        }
    }
     public function callback(Request $request)
    {
        // set konfigurasi midtrans
            Config::$serverKey =config('services.midtrans.serverKey');
            Config::$isproduction =config('services.midtrans.isproduction');
            Config::$isSanitized =config('services.midtrans.isSanitized');
            Config::$is3ds =config('services.midtrans.is3ds');

        //instance midtrans notification
            $notification = new Notification();

        //assigned ke variabel untuk memudahkan coding
            $status =$notification->transaction_status;  
            $type =$notification->payment_type; 
            $fraud =$notification->fraud_status;
            $order_id =$notification->order_id;

        //cari transaksi berdasarkan ID
            $transaction =Transaction::findOrFail($order_id);
            
        //handle notification status
            if($status == 'capture'){
                if($type == 'credit_card'){
                    if($fraud == 'challenge'){
                        $transaction->status ='PENDING';
                    }
                    else{
                        $transaction->status='SUCCESS';
                    }
                }
            }

            else if($status == 'settlement'){
                $transaction->status = 'SUCCESS';
            }
            else if($status == 'pending'){
                $transaction->status = 'PENDING';
            }
            else if($status == 'deny'){
                $transaction->status = 'CANCELLED';
            }
            else if($status == 'expire'){
                $transaction->status = 'CANCELLED';
            }
            else if($status == 'cancel'){
                $transaction->status = 'CANCELLED';
            }

        //simpan transaksi
            $transaction->save();
    }
}
