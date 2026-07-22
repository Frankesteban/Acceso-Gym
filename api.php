<?php
// api.php
header("Content-Type: application/json; charset=UTF-8");

// Evitar que errores o avisos de PHP rompan la estructura del JSON
ini_set('display_errors', 0);
error_reporting(E_ALL);

require_once 'conexion.php';

// Se captura 'action' desde la URL (?action=...) o desde el cuerpo JSON
$jsonInput = json_decode(file_get_contents("php://input"), true) ?? [];
$action = $_GET['action'] ?? $_POST['action'] ?? ($jsonInput['action'] ?? '');

try {
    switch ($action) {

        // ========================================================
        // 1. INICIO DE SESIÓN Y VERIFICACIÓN DE ROL
        // ========================================================
        case 'login':
            $username = trim($jsonInput['username'] ?? $_POST['username'] ?? '');
            $password = trim($jsonInput['password'] ?? $_POST['password'] ?? '');

            if ($username === '' || $password === '') {
                echo json_encode(["status" => "error", "message" => "Faltan datos obligatorios."]);
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
        // 2. CONSULTA DE SOCIO / AUTO-LOG DE INGRESO
        // ========================================================
        case 'consultar_socio':
            $documento = trim($_GET['documento'] ?? $_POST['documento'] ?? $jsonInput['documento'] ?? '');

            if ($documento === '') {
                echo json_encode(["status" => "error", "message" => "El número de documento es obligatorio"]);
                break;
            }

            $stmt = $pdo->prepare("SELECT * FROM miembros WHERE documento = ?");
            $stmt->execute([$documento]);
            $socio = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($socio) {
                $hoy = date('Y-m-d');
                $vencido = ($socio['fecha_pago'] < $hoy);

                // Si está activo, registra el acceso automáticamente
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
        // 3. REGISTRAR PAGO (DÍA O RENOVACIÓN MULTI-MES)
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

            echo json_encode(["status" => "success", "message" => "Transacción registrada con éxito"]);
            break;

        // ========================================================
        // 4. EDICIÓN DE SOCIO
        // ========================================================
        case 'editar_socio':
            if (($jsonInput['rol_usuario'] ?? $_POST['rol_usuario'] ?? '') !== 'ADMIN') {
                echo json_encode(["status" => "error", "message" => "Acceso denegado. Se requieren permisos de Administrador."]);
                break;
            }

            $id        = intval($jsonInput['id'] ?? $_POST['id'] ?? 0);
            $nombre    = trim($jsonInput['nombre'] ?? $_POST['nombre'] ?? '');
            $documento = trim($jsonInput['documento'] ?? $_POST['documento'] ?? '');
            $telefono  = trim($jsonInput['telefono'] ?? $_POST['telefono'] ?? '');

            $stmt = $pdo->prepare("UPDATE miembros SET nombre = ?, documento = ?, telefono = ? WHERE id = ?");
            $stmt->execute([$nombre, $documento, $telefono, $id]);

            echo json_encode(["status" => "success", "message" => "Datos actualizados correctamente"]);
            break;

        // ========================================================
        // 5. REPORTES Y CIERRE DE CAJA
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

            echo json_encode([
                "status"  => "success",
                "totales" => $totales,
                "logs"    => $logs
            ]);
            break;

        default:
            echo json_encode(["status" => "error", "message" => "Acción no válida o no especificada."]);
            break;
    }
} catch (Exception $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    echo json_encode([
        "status" => "error",
        "message" => "Error interno: " . $e->getMessage()
    ]);
}
?>
