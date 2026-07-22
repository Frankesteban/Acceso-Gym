<?php
// api.php
header("Content-Type: application/json; charset=UTF-8");
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Headers: *");
header("Access-Control-Allow-Methods: GET, POST, OPTIONS");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

ini_set('display_errors', 0);
error_reporting(E_ALL);

require_once 'conexion.php';

// Capturar payload JSON de forma totalmente segura
$rawInput = file_get_contents("php://input");
$jsonInput = json_decode($rawInput, true);

if (!is_array($jsonInput)) {
    $jsonInput = [];
}

// Mezclar todo lo recibido para no perder ninguna variable
$requestData = array_merge($_GET, $_POST, $jsonInput);

$action = $requestData['action'] ?? '';

try {
    switch ($action) {

        // ========================================================
        // 1. LOGIN
        // ========================================================
        case 'login':
            $username = trim($requestData['username'] ?? '');
            $password = trim($requestData['password'] ?? '');

            // Si por algún motivo siguen vacíos, revisamos si enviaron 'documento' o 'cliente'
            if (empty($username) && !empty($requestData['documento'])) {
                $username = trim($requestData['documento']);
            }

            if (empty($username) || empty($password)) {
                echo json_encode([
                    "status" => "error", 
                    "message" => "Faltan credenciales", 
                    "recibido" => $requestData // Para que veas exactamente qué llegó
                ]);
                break;
            }

            $stmt = $pdo->prepare("SELECT id, username, password, nombre_completo, rol FROM usuarios_sistema WHERE username = ?");
            $stmt->execute([$username]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($user && password_verify($password, $user['password'])) {
                echo json_encode([
                    "status" => "success",
                    "user" => [
                        "id" => $user['id'],
                        "username" => $user['username'],
                        "nombre" => $user['nombre_completo'],
                        "rol" => $user['rol']
                    ]
                ]);
            } else {
                echo json_encode(["status" => "error", "message" => "Usuario o contraseña incorrectos"]);
            }
            break;

        // ========================================================
        // 2. CONSULTAR SOCIO
        // ========================================================
        case 'consultar_socio':
            $documento = trim($requestData['documento'] ?? '');

            if (empty($documento)) {
                echo json_encode(["status" => "error", "message" => "Documento requerido"]);
                break;
            }

            $stmt = $pdo->prepare("SELECT * FROM miembros WHERE documento = ?");
            $stmt->execute([$documento]);
            $socio = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($socio) {
                $hoy = date('Y-m-d');
                $vencido = ($socio['fecha_pago'] < $hoy);

                if (!$vencido) {
                    $logStmt = $pdo->prepare("INSERT INTO logs_acceso (cliente_tag, documento, nombre, tipo_ingreso, monto, usuario_registro, fecha_hora) VALUES (?, ?, ?, 'MENSUALIDAD', 0.00, 'recepcion', NOW())");
                    $logStmt->execute([
                        $socio['cliente_tag'] ?? 'vidafit', 
                        $socio['documento'], 
                        $socio['nombre']
                    ]);
                }

                echo json_encode([
                    "status" => "success",
                    "encontrado" => true,
                    "vencido" => $vencido,
                    "socio" => $socio
                ]);
            } else {
                echo json_encode(["status" => "success", "encontrado" => false]);
            }
            break;

        // ========================================================
        // 3. REGISTRAR PAGO
        // ========================================================
        case 'registrar_pago':
            $documento     = trim($requestData['documento'] ?? '');
            $nombre        = trim($requestData['nombre'] ?? 'Socio');
            $tipoIngreso   = $requestData['tipo_ingreso'] ?? 'DIA';
            $meses         = max(1, intval($requestData['meses'] ?? 1));
            $monto         = floatval($requestData['monto'] ?? 0);
            $metodoPago    = $requestData['metodo_pago'] ?? 'EFECTIVO';
            $usuarioActual = trim($requestData['usuario_registro'] ?? 'recepcion');
            $clienteTag    = trim($requestData['cliente'] ?? 'vidafit');

            if (empty($documento)) {
                echo json_encode(["status" => "error", "message" => "Documento es obligatorio"]);
                break;
            }

            $pdo->beginTransaction();

            if ($tipoIngreso === 'RENOVACION_MES') {
                $stmt = $pdo->prepare("SELECT fecha_pago FROM miembros WHERE documento = ? FOR UPDATE");
                $stmt->execute([$documento]);
                $socio = $stmt->fetch(PDO::FETCH_ASSOC);

                if ($socio) {
                    $today = new DateTime('today');
                    $currentFechaPago = new DateTime($socio['fecha_pago']);

                    $baseDate = ($currentFechaPago > $today) ? $currentFechaPago : $today;
                    $baseDate->modify("+{$meses} month");
                    $nuevaFechaPago = $baseDate->format('Y-m-d');

                    $update = $pdo->prepare("UPDATE miembros SET fecha_pago = ? WHERE documento = ?");
                    $update->execute([$nuevaFechaPago, $documento]);
                }
            }

            $log = $pdo->prepare("INSERT INTO logs_acceso (cliente_tag, documento, nombre, tipo_ingreso, meses_pagados, monto, metodo_pago, usuario_registro, fecha_hora) VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())");
            $log->execute([$clienteTag, $documento, $nombre, $tipoIngreso, $meses, $monto, $metodoPago, $usuarioActual]);

            $pdo->commit();

            echo json_encode(["status" => "success", "message" => "Transacción registrada con éxito"]);
            break;

        // ========================================================
        // 4. REPORTES
        // ========================================================
        case 'obtener_reportes':
            $fechaInicio = $requestData['fecha_inicio'] ?? date('Y-m-d');
            $fechaFin    = $requestData['fecha_fin'] ?? date('Y-m-d');
            $tipo        = $requestData['tipo'] ?? 'TODOS';
            $metodo      = $requestData['metodo'] ?? 'TODOS';

            $query = "SELECT * FROM logs_acceso WHERE DATE(fecha_hora) BETWEEN ? AND ?";
            $params = [$fechaInicio, $fechaFin];

            if ($tipo !== 'TODOS') {
                $query .= " AND tipo_ingreso = ?";
                $params[] = $tipo;
            }

            if ($metodo !== 'TODOS') {
                $query .= " AND metodo_pago = ?";
                $params[] = $metodo;
            }

            $query .= " ORDER BY fecha_hora DESC";

            $stmt = $pdo->prepare($query);
            $stmt->execute($params);
            $logs = $stmt->fetchAll(PDO::FETCH_ASSOC);

            $totales = [
                "total_recaudado"     => 0,
                "efectivo"            => 0,
                "nequi_daviplata"     => 0,
                "tarjeta"             => 0,
                "conteo_dias"         => 0,
                "conteo_renovaciones" => 0
            ];

            foreach ($logs as $item) {
                $monto = floatval($item['monto']);
                $totales['total_recaudado'] += $monto;

                if ($item['metodo_pago'] === 'EFECTIVO') $totales['efectivo'] += $monto;
                if (in_array($item['metodo_pago'], ['NEQUI', 'DAVIPLATA'])) $totales['nequi_daviplata'] += $monto;
                if ($item['metodo_pago'] === 'TARJETA') $totales['tarjeta'] += $monto;

                if ($item['tipo_ingreso'] === 'DIA') $totales['conteo_dias']++;
                if ($item['tipo_ingreso'] === 'RENOVACION_MES') $totales['conteo_renovaciones']++;
            }

            echo json_encode([
                "status"  => "success",
                "totales" => $totales,
                "logs"    => $logs
            ]);
            break;

        default:
            echo json_encode([
                "status" => "error", 
                "message" => "Acción no especificada",
                "datos_recibidos" => $requestData
            ]);
            break;
    }
} catch (Exception $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    echo json_encode([
        "status" => "error",
        "message" => "Error PHP: " . $e->getMessage()
    ]);
}
?>
