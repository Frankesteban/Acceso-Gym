<?php
// api.php
require_once 'conexion.php';

$action = $_GET['action'] ?? $_POST['action'] ?? '';

switch ($action) {

    // ========================================================
    // 1. INICIO DE SESIÓN Y VERIFICACIÓN DE ROL
    // ========================================================
    case 'login':
        $data = json_decode(file_get_contents("php://input"), true);
        $username = trim($data['username'] ?? '');
        $password = trim($data['password'] ?? '');

        $stmt = $pdo->prepare("SELECT id, username, password, nombre_completo, rol FROM usuarios_sistema WHERE username = ?");
        $stmt->execute([$username]);
        $user = $stmt->fetch();

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
        $documento = $_GET['documento'] ?? '';
        
        $stmt = $pdo->prepare("SELECT * FROM miembros WHERE documento = ?");
        $stmt->execute([$documento]);
        $socio = $stmt->fetch();

        if ($socio) {
            $hoy = date('Y-m-d');
            $vencido = ($socio['fecha_pago'] < $hoy);

            // Si está activo, registra el acceso automáticamente
            if (!$vencido) {
                $logStmt = $pdo->prepare("INSERT INTO logs_acceso (cliente_tag, documento, nombre, tipo_ingreso, monto, usuario_registro, fecha_hora) VALUES (?, ?, ?, 'MENSUALIDAD', 0.00, 'recepcion', NOW())");
                $logStmt->execute([$socio['cliente_tag'], $socio['documento'], $socio['nombre']]);
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
        
        $documento     = $data['documento'];
        $nombre        = $data['nombre'];
        $tipoIngreso   = $data['tipo_ingreso']; // 'DIA' o 'RENOVACION_MES'
        $meses         = intval($data['meses'] ?? 1);
        $monto         = floatval($data['monto']);
        $metodoPago    = $data['metodo_pago'] ?? 'EFECTIVO';
        $usuarioActual = $data['usuario_registro'] ?? 'recepcion';

        if ($tipoIngreso === 'RENOVACION_MES') {
            // Obtener fecha actual de vencimiento
            $stmt = $pdo->prepare("SELECT fecha_pago FROM miembros WHERE documento = ?");
            $stmt->execute([$documento]);
            $socio = $stmt->fetch();

            $baseDate = new DateTime();
            if ($socio && new DateTime($socio['fecha_pago']) > $baseDate) {
                // Si la membresía aún no vence, se suman los meses desde la fecha de vencimiento actual
                $baseDate = new DateTime($socio['fecha_pago']);
            }
            
            // Sumar los N meses seleccionados
            $baseDate->modify("+{$meses} month");
            $nuevaFechaPago = $baseDate->format('Y-m-d');

            // Actualizar la fecha en la tabla miembros
            $update = $pdo->prepare("UPDATE miembros SET fecha_pago = ? WHERE documento = ?");
            $update->execute([$nuevaFechaPago, $documento]);
        }

        // Registrar en la tabla de auditoría / logs de caja
        $log = $pdo->prepare("INSERT INTO logs_acceso (cliente_tag, documento, nombre, tipo_ingreso, meses_pagados, monto, metodo_pago, usuario_registro, fecha_hora) VALUES ('vidafit', ?, ?, ?, ?, ?, ?, ?, NOW())");
        $log->execute([$documento, $nombre, $tipoIngreso, $meses, $monto, $metodoPago, $usuarioActual]);

        echo json_encode(["status" => "success", "message" => "Transacción registrada con éxito"]);
        break;

    // ========================================================
    // 4. EDICIÓN DE SOCIO (SOLO ROL ADMIN)
    // ========================================================
    case 'editar_socio':
        $data = json_decode(file_get_contents("php://input"), true);
        
        if (($data['rol_usuario'] ?? '') !== 'ADMIN') {
            echo json_encode(["status" => "error", "message" => "Acceso denegado. Se requieren permisos de Administrador."]);
            exit;
        }

        $id        = $data['id'];
        $nombre    = trim($data['nombre']);
        $documento = trim($data['documento']);
        $telefono  = trim($data['telefono']);

        $stmt = $pdo->prepare("UPDATE miembros SET nombre = ?, documento = ?, telefono = ? WHERE id = ?");
        $stmt->execute([$nombre, $documento, $telefono, $id]);

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
        $logs = $stmt->fetchAll();

        // Totales consolidados para el cierre
        $totales = [
            "total_recaudado" => 0,
            "efectivo" => 0,
            "nequi_daviplata" => 0,
            "tarjeta" => 0,
            "conteo_dias" => 0,
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
            "status" => "success",
            "totales" => $totales,
            "logs" => $logs
        ]);
        break;

    default:
        echo json_encode(["status" => "error", "message" => "Acción no válida"]);
        break;
}
?>