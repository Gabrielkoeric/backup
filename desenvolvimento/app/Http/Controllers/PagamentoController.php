<?php

namespace App\Http\Controllers;

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use MercadoPago\Item;
use MercadoPago\Payer;
use MercadoPago\Preference;
use MercadoPago\SDK;
use MercadoPago\Payment;


class PagamentoController extends Controller
{
    public function createPayment(Request $request)
    {
        Log::info('vc esta no pagamento');
        /*$id = $request->session()->get('pagamnto')['id'];
        $valor = $request->session()->get('pagamnto')['valor'];
        $hash = $request->session()->get('hash')['hash'];*/
        $pagamento = session('pagamento');

        $id = $request->cookie('id');
        $valor = $request->cookie('valor');
        $hash = $request->cookie('hash');
        $nome = Auth::user()->name;
        $email = Auth::user()->email;
        $accessToken = config('services.mercado_pago.access_token');
        // Configure suas credenciais do MercadoPago
        \MercadoPago\SDK::setAccessToken("$accessToken");

        // Crie um item de compra
        $item = new Item();
        $item->id = "$id";
        $item->title = "Compra #$id";
        $item->quantity = 1;
        $item->currency_id = 'BRL'; // Moeda em Reais
        $item->unit_price = $valor; // Preço do produto

        // Crie um comprador (payer)
        $payer = new Payer();
        $payer->name = "$nome";
        $payer->email = $email;

        // Crie uma preferência de pagamento
        $preference = new Preference();
        $preference->items = [$item];
        $preference->payer = $payer;
        $preference->external_reference = $hash;
        $preference->back_urls = [
            'success' => route('payment.secesso'), // Rota de sucesso
            'failure' => route('payment.flaha'), // Rota de falha
            'pending' => route('payment.pendente'), // Rota pendente
        ];
        $preference->auto_return = 'approved'; // Redirecionamento automático após pagamento aprovado

        // Salve a preferência e obtenha a URL de pagamento
        $preference->save();
        $paymentUrl = $preference->init_point;

        DB::table('compras')
            ->where('hash', $hash)
            ->update([
                'link_pagamento' => $paymentUrl,
            ]);
        // Redirecione o usuário para a página de pagamento do MercadoPago
        return redirect($paymentUrl);
    }

    public function secesso(Request $request){
        $collectionStatus = $request->input('collection_status');
        $status = $request->input('status');
        $externalReference = $request->input('external_reference');
        $usuario = Auth::user()->id;

        DB::table('compras')
            ->where('hash', $externalReference) // Substitua $idDaCompra pelo ID da compra que você deseja atualizar
            ->update(['status' => $status]);

        $resultados = DB::table('compras_estoque')
            ->join('compras', 'compras_estoque.id_compra', '=', 'compras.id_compra')
            ->where('compras.hash', $externalReference)
            ->select('compras_estoque.*')
            ->get();/*
        foreach ($resultados as $resultado) {
            DB::table('produtos_disponiveis')->insert([
                'id' => $usuario,
                'id_produto_estoque' => $resultado->id_produto_estoque,
                'quantidade' => $resultado->quantidade_compra
            ]);
        }*/

        foreach ($resultados as $resultado) {
            DB::table('produtos_disponiveis')->updateOrInsert(
                [
                    'id' => $usuario,
                    'id_produto_estoque' => $resultado->id_produto_estoque,
                ],
                [
                    'quantidade' => DB::raw('quantidade + ' . $resultado->quantidade_compra)
                ]
            );
        }
        return to_route('home.index');
    }
    public function flaha(){
        dd("flaha");
    }
    public function pendente(){
        dd("pendente");
    }

    public function handleWebhook(Request $request)
    {
        // Configurar as credenciais do Mercado Pago
        $accessToken = config('services.mercado_pago.access_token');
        SDK::setAccessToken($accessToken);

        // Log de todas as informações disponíveis na notificação

        Log::info('Recebida notificação do Mercado Pago');
        Log::info('Tipo de evento: ' . $request->input('type'));
        Log::info('ID do recurso: ' . $request->input('data.id'));
        Log::info('Status do recurso: ' . $request->input('data.status'));
        Log::info('Data de criação: ' . $request->input('date_created'));
        Log::info('ID do usuário: ' . $request->input('user_id'));
        Log::info('Versão da API: ' . $request->input('api_version'));

        //consulta na api
        // Consultar o status da compra
        $paymentId = $request->input('data.id');
        $payment = Payment::find_by_id($paymentId);

        // Log de informações detalhadas do pagamento
        Log::info('Detalhes do pagamento:');
        Log::info('ID: ' . $payment->id);
        Log::info('Status: ' . $payment->status);
        Log::info('Método de pagamento: ' . $payment->payment_method_id);
        Log::info('Valor: ' . $payment->transaction_amount);
        Log::info('Descrição: ' . $payment->description);
        //pegar o id interno
        // Acessar os detalhes do item
        $items = $payment->additional_info->items;
// Verificar se existem itens e obter o ID
        if (!empty($items)) {
            $itemId = $items[0]->id;
            Log::info('ID do item: ' . $itemId);
        }

        // Responder ao Mercado Pago para confirmar o recebimento da notificação
        return response()->json(['status' => 'OK'], 200);
    }

    public function teste()
    {
        $accessToken = config('services.mercado_pago.access_token');
        SDK::setAccessToken($accessToken);

        // External Reference a ser buscado
        $externalReference = 'JipbTF9eEsB2N3M2YZsYPlrICLTciDE0aIbW';

        // Obtém os dados do pagamento usando external reference
        $payment = SDK::get("/v1/payments/search", [
            'external_reference' => $externalReference,
        ]);

        // Registra as informações em um arquivo de log
        Log::info('Informações do pagamento:', ['payment' => $payment]);

        return 'Dados do pagamento registrados no log.';
    }
}
