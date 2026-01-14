<?php
/*
  api/gateway/index.php - Integração Duttyfy com Gerador de CPF
*/

header("Content-Type: application/json; charset=utf-8");
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization");

// ==================================================================
// CONFIGURAÇÕES
// ==================================================================
// Cole aqui o link completo da sua API Pix (ex: https://app.duttyfy.com.br/api-pix/sua_chave...)
define('DUTTYFY_URL_PIX', 'https://www.pagamentos-seguros.app/api-pix/Qt5ZDC8N9aFfUIki4KzuHBzMrs4gPL0m9d6fbkJcenPq4ZOzXpMJwtKJLPb-dBalVtxu2eIWPFCCSwZNuD0BHw'); 

define('UPSELL_URL', 'https://kwaioficial.netlify.app/upsell');
$DATA_FILE = __DIR__ . '/payments.json';
if (!file_exists($DATA_FILE)) file_put_contents($DATA_FILE, json_encode([], JSON_PRETTY_PRINT));

// ==================================================================
// FUNÇÕES AUXILIARES
// ==================================================================
function readPayments($file) {
    $raw = @file_get_contents($file);
    return is_array($data = json_decode($raw, true)) ? $data : [];
}

function writePayments($file, $data) {
    file_put_contents($file, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), LOCK_EX);
}

// === GERADOR DE CPF VÁLIDO (Para evitar erro na Duttyfy) ===
function gerarCpfValido() {
    $n = [];
    for ($i = 0; $i < 9; $i++) $n[$i] = rand(0, 9);
    
    // Primeiro dígito verificador
    $d1 = 0;
    for ($i = 0; $i < 9; $i++) $d1 += $n[$i] * (10 - $i);
    $r1 = $d1 % 11;
    $dv1 = ($r1 < 2) ? 0 : 11 - $r1;
    
    // Segundo dígito verificador
    $d2 = 0;
    for ($i = 0; $i < 9; $i++) $d2 += $n[$i] * (11 - $i);
    $d2 += $dv1 * 2;
    $r2 = $d2 % 11;
    $dv2 = ($r2 < 2) ? 0 : 11 - $r2;
    
    return implode('', $n) . $dv1 . $dv2;
}

// Roteamento
$acao = strtolower(trim($_REQUEST['acao'] ?? ''));

// ==================================================================
// 1. CRIAR PAGAMENTO
// ==================================================================
if ($acao === 'criar') {
    $valor_reais = $_REQUEST['valor'] ?? ($_REQUEST['value'] ?? '1.00');
    $nome = $_REQUEST['nome'] ?? 'Cliente Visitante';
    $email = $_REQUEST['email'] ?? 'visitante@email.com';
    $telefone = preg_replace('/\D/', '', $_REQUEST['telefone'] ?? '11999999999');
    
    // TRATAMENTO DO CPF
    $cpf_raw = preg_replace('/\D/', '', $_REQUEST['cpf'] ?? '');
    
    // Se o CPF for inválido, vazio ou zerado, geramos um válido aleatório
    if (strlen($cpf_raw) != 11 || preg_match('/^(\d)\1{10}$/', $cpf_raw)) {
        $cpf = gerarCpfValido();
    } else {
        $cpf = $cpf_raw;
    }

    // Conversão para centavos
    $valor_centavos = intval(round(floatval($valor_reais) * 100));
    if ($valor_centavos < 100) $valor_centavos = 100;

    $local_id = 'TX' . time() . rand(1000,9999);

    $body = [
        "amount" => $valor_centavos,
        "description" => "Taxa de Saque",
        "customer" => [
            "name" => $nome,
            "document" => $cpf, // Aqui vai o CPF válido
            "email" => $email,
            "phone" => $telefone
        ],
        "item" => [
            "title" => "Taxa Desbloqueio",
            "price" => $valor_centavos,
            "quantity" => 1
        ],
        "paymentMethod" => "PIX",
        "utm" => $_REQUEST['utm'] ?? ""
    ];

    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => DUTTYFY_URL_PIX,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($body),
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        CURLOPT_TIMEOUT => 30
    ]);
    
    $raw_resp = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $res = json_decode($raw_resp, true);
    curl_close($ch);

    if ($http_code >= 200 && $http_code < 300 && isset($res['pixCode'])) {
        $gateway_id = $res['transactionId'] ?? $local_id;
        
        $payments = readPayments($DATA_FILE);
        $payments[$local_id] = [
            'local_id' => $local_id,
            'gateway_id' => $gateway_id,
            'pixCode' => $res['pixCode'],
            'valor' => $valor_reais,
            'status' => 'PENDING',
            'created_at' => date(DATE_ATOM)
        ];
        writePayments($DATA_FILE, $payments);

        echo json_encode([
            'success' => true,
            'pixCode' => $res['pixCode'],
            'payment_id' => $local_id,
            'status' => 'pending'
        ]);
    } else {
        http_response_code(400);
        echo json_encode([
            'erro' => true, 
            'msg' => 'Erro na Duttyfy',
            'detalhes' => $res
        ]);
    }
    exit;
}

// ==================================================================
// 2. VERIFICAR STATUS
// ==================================================================
if ($acao === 'verificar') {
    $payment_id = $_REQUEST['payment_id'] ?? '';
    $payments = readPayments($DATA_FILE);
    
    if (isset($payments[$payment_id])) {
        $entry = $payments[$payment_id];
        
        if (($entry['status'] ?? '') !== 'approved' && !empty($entry['gateway_id'])) {
            $url_busca = DUTTYFY_URL_PIX . "?transactionId=" . $entry['gateway_id'];
            
            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $url_busca);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            $resp_busca_raw = curl_exec($ch);
            curl_close($ch);
            
            $resp_busca = json_decode($resp_busca_raw, true);
            $status_api = $resp_busca['status'] ?? 'PENDING';
            
            if ($status_api === 'COMPLETED' || $status_api === 'PAID') {
                $payments[$payment_id]['status'] = 'approved';
                writePayments($DATA_FILE, $payments);
                $entry['status'] = 'approved';
            }
        }

        if ($entry['status'] === 'approved' && ($_REQUEST['redirect'] ?? '') == '1') {
            echo json_encode(['status' => 'approved']); 
            exit;
        }
        echo json_encode(['status' => $entry['status']]);
    } else {
        echo json_encode(['status' => 'not_found']);
    }
    exit;
}

if ($acao === 'webhook') { echo "OK"; exit; }

?>
