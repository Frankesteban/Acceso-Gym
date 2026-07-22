<?php
// api.php
header("Content-Type: application/json; charset=UTF-8");

// Silenciar warnings en producción para evitar corruptibilidad en el JSON de respuesta
ini_set('display_errors', 0);
error_reporting(E_ALL);

require_once 'conexion.php';

$action = $_GET['action'] ?? $_POST['action'] ?? '';

try {
    switch ($action) {

        // ========================================================
        // 1. INICIO DE SESIÓN Y VERIFICACIÓN DE ROL
        // ========================================================
        case 'login':
            $data = json_decode(file_get_contents("php://input"), true);
            $username = trim($data['username'] ?? '');
            $password = trim($data['password'] ?? '');

            if (empty($username) || empty($password)) {
                echo json_encode(["status" => "error", "message" => "Usuario y contraseña son requeridos"]);
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
            $documento = trim($_GET['documento'] ?? '');

            if (empty($documento)) {
                echo json_encode(["status" => "error", "message" => "El número de documento es obligatorio"]);
                break;
            }

            $stmt = $pdo->prepare("SELECT * FROM miembros WHERE documento = ?");
            $stmt->execute([$documento]);
            $socio = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($socio) {
                $hoy = new DateTime('today');
                $fechaPago = new DateTime($socio['fecha_pago']);
                $vencido = ($fechaPago < $hoy);

                // Si está activo, registra el acceso automáticamente de forma segura
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
            $data = json_decode(file_get_contents("php://input"), true);

            $documento     = trim($data['documento'] ?? '');
            $nombre        = trim($data['nombre'] ?? '');
            $tipoIngreso   = $data['tipo_ingreso'] ?? ''; // 'DIA' o 'RENOVACION_MES'
            $meses         = max(1, intval($data['meses'] ?? 1));
            $monto         = floatval($data['monto'] ?? 0);
            $metodoPago    = $data['metodo_pago'] ?? 'EFECTIVO';
            $usuarioActual = trim($data['usuario_registro'] ?? 'recepcion');

            if (empty($documento) || empty($nombre) || empty($tipoIngreso)) {
                echo json_encode(["status" => "error", "message" => "Faltan parámetros obligatorios para registrar el pago"]);
                break;
            }

            // Uso de transacción para evitar inconsistencias en la BD
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

                // Si la membresía ya venció, se empieza a contar desde hoy
                $baseDate = ($currentFechaPago > $today) ? $currentFechaPago : $today;

                // Sumar los N meses manteniendo el fin de mes intacto si aplica
                $baseDate->modify("+{$meses} month");
                $nuevaFechaPago = $baseDate->format('Y-m-d');

                $update = $pdo->prepare("UPDATE miembros SET fecha_pago = ? WHERE documento = ?");
                $update->execute([$nuevaFechaPago, $documento]);
            }

            // Registrar en la tabla de auditoría / logs de caja
            $log = $pdo->prepare("INSERT INTO logs_acceso (cliente_tag, documento, nombre, tipo_ingreso, meses_pagados, monto, metodo_pago, usuario_registro, fecha_hora) VALUES ('vidafit', ?, ?, ?, ?, ?, ?, ?, NOW())");
            $log->execute([$documento, $nombre, $tipoIngreso, $meses, $monto, $metodoPago, $usuarioActual]);

            $pdo->commit();

            echo json_encode(["status" => "success", "message" => "Transacción registrada con éxito"]);
            break;

        // ========================================================
        // 4. EDICIÓN DE SOCIO (VERIFICACIÓN SEGURA DE ROL EN BD)
        // ========================================================
        case 'editar_socio':
            $data = json_decode(file_get_contents("php://input"), true);

            $idUsuarioAccion = intval($data['id_usuario_accion'] ?? 0);
            $idSocio         = intval($data['id'] ?? 0);
            $nombre          = trim($data['nombre'] ?? '');
            $documento       = trim($data['documento'] ?? '');
            $telefono        = trim($data['telefono'] ?? '');

            if ($idUsuarioAccion <= 0 || $idSocio <= 0 || empty($nombre) || empty($documento)) {
                echo json_encode(["status" => "error", "message" => "Datos de actualización o de sesión inválidos"]);
                break;
            }

            // Validar rol en base de datos en lugar de confiar en el frontend
            $stmtRol = $pdo->prepare("SELECT rol FROM usuarios_sistema WHERE id = ?");
            $stmtRol->execute([$idUsuarioAccion]);
            $userAccion = $stmtRol->fetch(PDO::FETCH_ASSOC);

            if (!$userAccion || $userAccion['rol'] !== 'ADMIN') {
                echo json_encode(["status" => "error", "message" => "Acceso denegado. Se requieren permisos de Administrador."]);
                break;
            }

            $stmt = $pdo->prepare("UPDATE miembros SET nombre = ?, documento = ?, telefono = ? WHERE id = ?");
            $stmt->execute([$nombre, $documento, $telefono, $idSocio]);

            echo json_encode(["status" => "success", "message" => "Datos actualizados correctamente"]);
            break;

        // ========================================================
        // 5. REPORTES Y CIERRE DE CAJA (FILTROS)
        // ========================================================
        case 'obtener_reportes':
            $fechaInicio = $_GET['fecha_inicio'] ?? date('Y-m-d');
            $fechaFin    = $_GET['fecha_fin'] ?? date('Y-m-d');
            $tipo        = $_GET['tipo'] ?? 'TODOS';
            $metodo      = $_GET['metodo'] ?? 'TODOS';

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

                if ($item['metodo_pago'] === 'EFECTIVO') {
                    $totales['efectivo'] += $monto;
                }
                if (in_array($item['metodo_pago'], ['NEQUI', 'DAVIPLATA'])) {
                    $totales['nequi_daviplata'] += $monto;
                }
                if ($item['metodo_pago'] === 'TARJETA') {
                    $totales['tarjeta'] += $monto;
                }

                if ($item['tipo_ingreso'] === 'DIA') {
                    $totales['conteo_dias']++;
                }
                if ($item['tipo_ingreso'] === 'RENOVACION_MES') {
                    $totales['conteo_renovaciones']++;
                }
            }

            echo json_encode([
                "status"  => "success",
                "totales" => $totales,
                "logs"    => $logs
            ]);
            break;

        default:
            echo json_encode(["status" => "error", "message" => "Acción no válida"]);
            break;
    }
} catch (Exception $e) {
    // Si la transacción estaba activa, la revierte ante cualquier excepción
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    
    echo json_encode([
        "status" => "error",
        "message" => "Error interno en el servidor: " . $e->getMessage()
    ]);
}
?>
