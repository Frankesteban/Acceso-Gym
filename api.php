<?php
// api.php
header("Content-Type: application/json; charset=UTF-8");
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Headers: Content-Type, Access-Control-Allow-Headers, Authorization, X-Requested-With");
header("Access-Control-Allow-Methods: GET, POST, OPTIONS");

// Manejo de peticiones preflight OPTIONS si aplicas CORS desde Vercel
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

// Ocultar warnings de PHP para que no dañen la estructura del JSON devuelto
ini_set('display_errors', 0);
error_reporting(E_ALL);

require_once 'conexion.php';

// Leer payload JSON si existe
$rawInput = file_get_contents("php://input");
$jsonInput = json_decode($rawInput, true) ?? [];

// Determinar la acción enviada en URL (?action=...) o dentro del JSON
$action = $_GET['action'] ?? $_POST['action'] ?? ($jsonInput['action'] ?? '');

try {
    switch ($action) {

        // ========================================================
        // 1. INICIO DE SESIÓN Y VERIFICACIÓN DE ROL
        // ========================================================
        case 'login':
            // Buscar credenciales en cualquier método de envío
            $username = trim($jsonInput['username'] ?? $_POST['username'] ?? $_GET['username'] ?? '');
            $password = trim($jsonInput['password'] ?? $_POST['password'] ?? $_GET['password'] ?? '');

            if ($username === '' || $password === '') {
                http_response_code(400);
                echo json_encode(["status" => "error", "message" => "Faltan datos obligatorios."]);
                break;
            }

            $stmt = $pdo->prepare("SELECT id, username, password, nombre_completo, rol FROM usuarios_sistema WHERE username = ?");
            $stmt->execute([$username]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($user && password_verify($password, $user['password'])) {
                http_response_code(200);
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
                http_response_code(200); // 200 OK pero con status error para manejo en frontend
                echo json_encode(["status" => "error", "message" => "Usuario o contraseña incorrectos"]);
            }
            break;

        // ========================================================
        // 2. CONSULTA DE SOCIO / AUTO-LOG DE INGRESO
        // ========================================================
        case 'consultar_socio':
            $documento = trim($_GET['documento'] ?? $_POST['documento'] ?? $jsonInput['documento'] ?? '');

            if ($documento === '') {
                http_response_code(400);
                echo json_encode(["status" => "error", "message" => "El número de documento es obligatorio"]);
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

                http_response_code(200);
                echo json_encode([
                    "status" => "success",
                    "encontrado" => true,
                    "vencido" => $vencido,
                    "socio" => $socio
                ]);
            } else {
                http_response_code(200);
                echo json_encode(["status" => "success", "encontrado" => false]);
            }
            break;

        // ========================================================
        // 3. REGISTRAR PAGO
        // ========================================================
        case 'registrar_pago':
            $documento     = trim($jsonInput['documento'] ?? $_POST['documento'] ?? '');
            $nombre        = trim($jsonInput['nombre'] ?? $_POST['nombre'] ?? 'Socio');
            $tipoIngreso   = $jsonInput['tipo_ingreso'] ?? $_POST['tipo_ingreso'] ?? 'DIA';
            $meses         = max(1, intval($jsonInput['meses'] ?? $_POST['meses'] ?? 1));
            $monto         = floatval($jsonInput['monto'] ?? $_POST['monto'] ?? 0);
            $metodoPago    = $jsonInput['metodo_pago'] ?? $_POST['metodo_pago'] ?? 'EFECTIVO';
            $usuarioActual = trim($jsonInput['usuario_registro'] ?? $_POST['usuario_registro'] ?? 'recepcion');
            $clienteTag    = trim($jsonInput['cliente'] ?? 'vidafit');

            if ($documento === '') {
                http_response_code(400);
                echo json_encode(["status" => "error", "message" => "El campo documento es obligatorio para el registro"]);
                break;
            }

            $pdo->beginTransaction();

            if ($tipoIngreso === 'RENOVACION_MES') {
                $stmt = $pdo->prepare("SELECT fecha_pago FROM miembros WHERE documento = ? FOR UPDATE");
                $stmt->execute([$documento]);
                $socio = $stmt->fetch(PDO::FETCH_ASSOC);

                if (!$socio) {
                    $pdo->rollBack();
                    http_response_code(200);
                    echo json_encode(["status" => "error", "message" => "El socio no existe en el sistema"]);
                    break;
                }

                $today = new DateTime('today');
                $currentFechaPago = new DateTime($socio['fecha_pago']);

                $baseDate = ($currentFechaPago > $today) ? $currentFechaPago : $today;
                $baseDate->modify("+{$meses} month");
                $nuevaFechaPago = $baseDate->format('Y-m-d');

                $update = $pdo->prepare("UPDATE miembros SET fecha_pago = ? WHERE documento = ?");
                $update->execute([$nuevaFechaPago, $documento]);
            }

            $log = $pdo->prepare("INSERT INTO logs_acceso (cliente_tag, documento, nombre, tipo_ingreso, meses_pagados, monto, metodo_pago, usuario_registro, fecha_hora) VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())");
            $log->execute([$clienteTag, $documento, $nombre, $tipoIngreso, $meses, $monto, $metodoPago, $usuarioActual]);

            $pdo->commit();

            http_response_code(200);
            echo json_encode(["status" => "success", "message" => "Transacción registrada con éxito"]);
            break;

        // ========================================================
        // 4. REPORTES
        // ========================================================
        case 'obtener_reportes':
            $fechaInicio = $_GET['fecha_inicio'] ?? $jsonInput['fecha_inicio'] ?? date('Y-m-d');
            $fechaFin    = $_GET['fecha_fin'] ?? $jsonInput['fecha_fin'] ?? date('Y-m-d');
            $tipo        = $_GET['tipo'] ?? $jsonInput['tipo'] ?? 'TODOS';
            $metodo      = $_GET['metodo'] ?? $jsonInput['metodo'] ?? 'TODOS';

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

            http_response_code(200);
            echo json_encode([
                "status"  => "success",
                "totales" => $totales,
                "logs"    => $logs
            ]);
            break;

        default:
            http_response_code(400);
            echo json_encode(["status" => "error", "message" => "Acción no válida o no especificada."]);
            break;
    }
} catch (Exception $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    http_response_code(500);
    echo json_encode([
        "status" => "error",
        "message" => "Error interno: " . $e->getMessage()
    ]);
}
?>
