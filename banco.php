#!/usr/bin/php -q
<?php
require('phpagi.php');
$agi = new AGI();

// Conexión a MariaDB
$servername = "localhost";
$username   = "root";
$password   = "1234";
$dbname     = "banco";

$conn = new mysqli($servername, $username, $password, $dbname);
if ($conn->connect_error) {
    $agi->exec("AGI", "googletts.agi,\"Error al conectar con la base de datos\",es");
    exit;
}

// Bienvenida
$agi->exec("AGI", "googletts.agi,\"Bienvenido al sistema bancario virtual\",es");
$agi->exec("Wait", "1");

// Pedir número de cuenta
$agi->exec("AGI", "googletts.agi,\"Por favor marque su número de identificación y presione numeral\",es");
$agi->stream_file('beep', '#');
$result = $agi->get_data('beep', 10000, 10);
$numero = trim($result['result']);

// Validar cuenta
if (empty($numero)) {
    $agi->exec("AGI", "googletts.agi,\"No se recibió ningún número de cuenta. Hasta luego\",es");
    $agi->hangup();
    exit;
}

$res = $conn->query("SELECT nombre, saldo FROM cuentas WHERE numero='$numero'");
if (!$res || $res->num_rows == 0) {
    $agi->exec("AGI", "googletts.agi,\"La cuenta ingresada no existe. Hasta luego\",es");
    $agi->hangup();
    exit;
}
$cuenta = $res->fetch_assoc();
$nombre = $cuenta['nombre'];
$saldo = $cuenta['saldo'];

// Saldo actual
$agi->exec("AGI", "googletts.agi,\"Hola $nombre. Su saldo actual es de $saldo pesos\",es");

// Menú principal
$agi->exec("AGI", "googletts.agi,\"Para consultar saldo marque 1 para consignar marque 2 para transferir marque 3\",es");
$op = $agi->get_data('beep', 8000, 1)['result'];

switch ($op) {
    case '1': // Consultar saldo
        $agi->exec("AGI", "googletts.agi,\"Su saldo actual es de $saldo pesos\",es");
        // Registrar movimiento
        $conn->query("INSERT INTO movimientos (cuenta_origen, tipo, valor, descripcion) VALUES ('$numero','consulta',0,'Consulta de saldo')");
        break;

    case '2': // Consignación
        $agi->exec("AGI", "googletts.agi,\"Ingrese el valor a consignar y precione numeral\",es");
        $valor = $agi->get_data('beep', 8000, 10)['result'];
        if (empty($valor) || !is_numeric($valor)) {
            $agi->exec("AGI", "googletts.agi,\"Valor no válido. Operación cancelada\",es");
            break;
        }
        // Confirmar
        $agi->exec("AGI", "googletts.agi,\"Usted va a consignar $valor pesos. Para confirmar marque 1 para corregir marque 2\",es");
        $conf = $agi->get_data('beep', 8000, 1)['result'];
        if ($conf == '1') {
            $nuevo_saldo = $saldo + $valor;
            $conn->query("UPDATE cuentas SET saldo=$nuevo_saldo WHERE numero='$numero'");
            $conn->query("INSERT INTO movimientos (cuenta_origen, tipo, valor, descripcion) VALUES ('$numero','consignacion',$valor,'Consignación de saldo')");
            $agi->exec("AGI", "googletts.agi,\"Consignación realizada exitosamente. Su nuevo saldo es de $nuevo_saldo pesos\",es");
        } else {
            $agi->exec("AGI", "googletts.agi,\"Operación cancelada\",es");
        }
        break;

    case '3': // Transferencia
        $agi->exec("AGI", "googletts.agi,\"Ingrese el número de cuenta destino y precione numeral\",es");
        $destino = $agi->get_data('beep', 8000, 10)['result'];
        $agi->exec("AGI", "googletts.agi,\"Ingrese el valor a transferir\",es");
        $valor = $agi->get_data('beep', 8000, 10)['result'];

        if (empty($destino) || empty($valor) || !is_numeric($valor)) {
            $agi->exec("AGI", "googletts.agi,\"Datos no válidos. Operación cancelada\",es");
            break;
        }

        // Confirmar
        $agi->exec("AGI", "googletts.agi,\"Usted va a transferir $valor pesos a la cuenta $destino. Para confirmar marque 1 para cancelar marque 2\",es");
        $conf = $agi->get_data('beep', 8000, 1)['result'];

        if ($conf == '1') {
            // Validar cuenta destino
            $res_dest = $conn->query("SELECT saldo FROM cuentas WHERE numero='$destino'");
            if (!$res_dest || $res_dest->num_rows == 0) {
                $agi->exec("AGI", "googletts.agi,\"La cuenta destino no existe. Operación cancelada\",es");
            } elseif ($saldo < $valor) {
                $agi->exec("AGI", "googletts.agi,\"Saldo insuficiente para realizar la transferencia\",es");
            } else {
                $saldo_dest = $res_dest->fetch_assoc()['saldo'];
                $nuevo_origen = $saldo - $valor;
                $nuevo_destino = $saldo_dest + $valor;

                $conn->query("UPDATE cuentas SET saldo=$nuevo_origen WHERE numero='$numero'");
                $conn->query("UPDATE cuentas SET saldo=$nuevo_destino WHERE numero='$destino'");
                $conn->query("INSERT INTO movimientos (cuenta_origen, cuenta_destino, tipo, valor, descripcion) VALUES ('$numero','$destino','transferencia',$valor,'Transferencia')");
                $agi->exec("AGI", "googletts.agi,\"Transferencia exitosa. Su nuevo saldo es de $nuevo_origen pesos\",es");
            }
        } else {
            $agi->exec("AGI", "googletts.agi,\"Operación cancelada\",es");
        }
        break;

    default:
        $agi->exec("AGI", "googletts.agi,\"Opción no válida\",es");
        break;
}

// Fin de llamada
$agi->exec("AGI", "googletts.agi,\"Gracias por usar el sistema bancario virtual. Hasta pronto\",es");
$agi->hangup();
$conn->close();
?>

