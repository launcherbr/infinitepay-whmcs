<?php
/**
 * Módulo InfinitePay API para WHMCS
 * Desenvolvido por Launcher Tech
 * launcher.com.br - licencas.digital
 * Versão: Final Robusta (Com Tratamento de Erros e Correção de Handle e Design Visual)
 */

if (!defined("WHMCS")) {
    die("This file cannot be accessed directly");
}

function infinitepay_MetaData()
{
    return array(
        'DisplayName' => 'InfinitePay API (Pix/Cartão)',
        'APIVersion' => '1.1',
        'Description' => 'Receba pagamentos via Link de Checkout (Pix e Cartão) com baixa automática via Webhook Dinâmico e validação de segurança.',
    );
}

function infinitepay_config()
{
    $systemUrl = \WHMCS\Config\Setting::getValue('SystemURL');
    $webhookUrl = rtrim($systemUrl, '/') . '/modules/gateways/callback/infinitepay.php';

    return array(
        'FriendlyName' => array(
            'Type' => 'System',
            'Value' => 'InfinitePay API',
        ),
        'infiniteHandle' => array(
            'FriendlyName' => 'A sua InfiniteTag (Handle)',
            'Type' => 'text',
            'Size' => '60',
            'Description' => '<br><small class="text-muted" style="color:#777;">O seu utilizador na app InfinitePay (sem @ ou $).</small>',
        ),
        'instructions' => array(
            'FriendlyName' => 'Instruções',
            'Type' => 'textarea',
            'Rows' => '3',
            'Default' => 'Clique no botão abaixo para pagar com segurança via InfinitePay.',
            'Description' => '<br><small class="text-muted" style="color:#777;">Mensagem exibida ao cliente acima do botão de pagamento na fatura.</small>',
        ),
        'webhookInfo' => array(
            'FriendlyName' => 'Webhook URL (Automático)',
            'Type' => 'text',
            'Description' => '
                <script>
                    // Esconde a caixa de input padrão do WHMCS
                    jQuery("input[name=\'field[webhookInfo]\']").hide();
                    
                    function copyInfinitePayUrl() {
                        var urlField = document.getElementById("infiniteWebhookUrl");
                        urlField.select();
                        document.execCommand("copy");
                        var btn = document.getElementById("btnCopyInfinite");
                        btn.innerHTML = "<i class=\"fas fa-check\"></i> Copiado!";
                        setTimeout(function() { btn.innerHTML = "<i class=\"fas fa-copy\"></i> Copiar"; }, 2000);
                    }
                </script>
                <div class="alert alert-info" style="margin: 5px 0 0 0; padding: 15px; border-radius: 5px; color: #31708f; background-color: #d9edf7; border-color: #bce8f1;">
                    <div style="margin-bottom: 10px;">
                        <i class="fas fa-cogs"></i> <strong>Informação:</strong> O módulo enviará automaticamente a URL abaixo para a InfinitePay a cada transação para garantir a baixa automática.
                    </div>
                    <div style="display: flex; gap: 5px; max-width: 600px;">
                        <input type="text" id="infiniteWebhookUrl" value="' . $webhookUrl . '" class="form-control" readonly onclick="this.select();" style="cursor:pointer; background-color: #fff; flex-grow: 1;">
                        <button type="button" id="btnCopyInfinite" class="btn btn-info" onclick="copyInfinitePayUrl()" style="white-space: nowrap;"><i class="fas fa-copy"></i> Copiar</button>
                    </div>
                </div>',
        ),
    );
}

function infinitepay_link($params)
{
    // 1. Preparação dos Dados
    // CORREÇÃO APLICADA: Aceita letras, números, underline (_) e traço (-)
    $handle = preg_replace('/[^a-zA-Z0-9_\-]/', '', $params['infiniteHandle']);
    
    $invoiceId = $params['invoiceid'];
    $amountCents = (int) (round($params['amount'], 2) * 100);

    // Verificação de valor mínimo
    if ($amountCents < 100) {
        return '<div class="alert alert-warning">Valor mínimo para InfinitePay é R$ 1,00.</div>';
    }

    $systemUrl = $params['systemurl'];
    $returnUrl = $systemUrl . 'viewinvoice.php?id=' . $invoiceId;
    $webhookUrl = $systemUrl . 'modules/gateways/callback/infinitepay.php';

    // 2. Tratamento de Cliente (Blindagem contra erros de telefone)
    $clientName = substr($params['clientdetails']['firstname'] . ' ' . $params['clientdetails']['lastname'], 0, 100);
    $clientEmail = $params['clientdetails']['email'];
    
    // Limpa telefone (deixa só números)
    $rawPhone = preg_replace('/[^0-9]/', '', $params['clientdetails']['phonenumber']);
    
    // Ajuste DDI Brasil: Se tem 10 ou 11 digitos e não começa com 55, adiciona.
    if (strlen($rawPhone) >= 10 && substr($rawPhone, 0, 2) != '55') {
        $rawPhone = '55' . $rawPhone;
    }
    // Garante o formato E.164 (+55...)
    $clientPhone = '+' . $rawPhone;

    // 3. Montagem do Payload
    $payload = array(
        "handle" => $handle,
        "redirect_url" => $returnUrl,
        "webhook_url" => $webhookUrl,
        "order_nsu" => (string) $invoiceId,
        "items" => array(
            array(
                "quantity" => 1,
                "price" => $amountCents,
                "description" => "Fatura #" . $invoiceId
            )
        )
    );

    // LÓGICA DE SEGURANÇA:
    // Só envia dados do cliente se o telefone parecer válido (min 12 dígitos contando o 55).
    // Se estiver errado, envia sem cliente para garantir que o link seja gerado.
    if (strlen($rawPhone) >= 12) { 
        $payload["customer"] = array(
            "name" => $clientName,
            "email" => $clientEmail,
            "phone_number" => $clientPhone
        );
    }

    // 4. Envio para API
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, "https://api.checkout.infinitepay.io/links");
    curl_setopt($ch, CURLOPT_POST, 1);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30); // Evita travar o WHMCS se a API demorar
    curl_setopt($ch, CURLOPT_HTTPHEADER, array('Content-Type: application/json'));
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false); // Evita erros de SSL em alguns servidores
    
    $response = curl_exec($ch);
    $curlError = curl_error($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    $jsonResponse = json_decode($response);

    // LOG PARA DEBUG (Visível em Utilitários > Logs > Log de Gateway)
    logTransaction($params['name'], [
        'Handle' => $handle,
        'Payload' => $payload,
        'Response' => $response,
        'CurlError' => $curlError
    ], 'Link Generation');

    // 5. Retorno Visual
    if (($httpCode == 201 || $httpCode == 200) && isset($jsonResponse->url)) {
        $checkoutUrl = $jsonResponse->url;
        
        return '
        <div style="text-align:center;">
            <p>' . nl2br($params['instructions']) . '</p>
            <a href="' . $checkoutUrl . '" class="btn btn-success btn-lg" target="_blank" style="background-color: #00c853; border-color: #00c853;">
                <i class="fas fa-shopping-cart"></i> Pagar Agora
            </a>
            <br><small class="text-muted">Pix ou Cartão via InfinitePay</small>
        </div>';
    } else {
        // Tratamento de Erro Visual para o Admin
        $errorMsg = "Erro ao comunicar com InfinitePay.";
        
        if ($curlError) {
            $errorMsg = "Erro de Conexão: " . $curlError;
        } elseif (isset($jsonResponse->message)) {
            $errorMsg = "Retorno da API: " . $jsonResponse->message;
            if (isset($jsonResponse->errors)) {
                $errorMsg .= " (" . json_encode($jsonResponse->errors) . ")";
            }
        }
        
        return '<div class="alert alert-danger"><strong>Falha InfinitePay:</strong> ' . $errorMsg . '</div>';
    }
}
?>
