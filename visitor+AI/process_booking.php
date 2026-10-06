<?php
require_once __DIR__ . '/includes/database.php';
$user = api_require_login($conn);
api_require_role($user, 'guest');
api_require_method('POST');

$roomId = filter_var($_POST['room_id'] ?? null, FILTER_VALIDATE_INT);
$checkIn = trim($_POST['check_in'] ?? '');
$checkOut = trim($_POST['check_out'] ?? '');
$guestCount = filter_var($_POST['guests_count'] ?? null, FILTER_VALIDATE_INT);
$specialRequests = trim($_POST['special_requests'] ?? '');
$inDate = DateTimeImmutable::createFromFormat('!Y-m-d', $checkIn);
$inErrors = DateTimeImmutable::getLastErrors();
$outDate = DateTimeImmutable::createFromFormat('!Y-m-d', $checkOut);
$outErrors = DateTimeImmutable::getLastErrors();
$validIn = $inDate && ($inErrors === false || ($inErrors['warning_count'] === 0 && $inErrors['error_count'] === 0)) && $inDate->format('Y-m-d') === $checkIn;
$validOut = $outDate && ($outErrors === false || ($outErrors['warning_count'] === 0 && $outErrors['error_count'] === 0)) && $outDate->format('Y-m-d') === $checkOut;
$today = new DateTimeImmutable('today');

if (!$roomId || !$validIn || !$validOut || !$guestCount || $guestCount > 10 || strlen($specialRequests) > 2000) {
    api_json(false, 'Enter a valid room, date range, guest count, and special request.', null, 400);
    exit;
}
if ($inDate < $today || $inDate > $today->modify('+365 days') || $outDate <= $inDate || $outDate > $inDate->modify('+90 days')) {
    api_json(false, 'Choose dates within the next year and a stay of 1 to 90 nights.', null, 400);
    exit;
}
$nights = (int)$inDate->diff($outDate)->days;
$userId = (int)$user['id'];

$conn->begin_transaction();
try {
    $roomStmt = $conn->prepare('SELECT id, status, price, capacity FROM rooms WHERE id=? FOR UPDATE');
    $roomStmt->bind_param('i', $roomId);
    $roomStmt->execute();
    $room = $roomStmt->get_result()->fetch_assoc();
    if (!$room) {
        throw new DomainException('Room not found.');
    }
    if ($room['status'] !== 'available') {
        throw new DomainException('Room is currently ' . $room['status'] . '.');
    }
    if ($guestCount > (int)$room['capacity']) {
        throw new DomainException('Guest count exceeds this room capacity.');
    }

    $overlap = $conn->prepare("SELECT id FROM bookings WHERE room_id=? AND status NOT IN ('cancelled','checked_out') AND check_in < ? AND check_out > ? LIMIT 1");
    $overlap->bind_param('iss', $roomId, $checkOut, $checkIn);
    $overlap->execute();
    if ($overlap->get_result()->fetch_assoc()) {
        throw new DomainException('Room is already booked for those dates.');
    }

    $bookingRef = 'BK' . strtoupper(bin2hex(random_bytes(8)));
    $invoiceRef = 'INV' . strtoupper(bin2hex(random_bytes(8)));
    $roomCents = (int)round((float)$room['price'] * 100) * $nights;
    $roomTotal = number_format($roomCents / 100, 2, '.', '');
    $tax = number_format((int)round($roomCents * 0.15) / 100, 2, '.', '');
    $invoiceTotal = number_format(($roomCents + (int)round($roomCents * 0.15)) / 100, 2, '.', '');

    $insertBooking = $conn->prepare("INSERT INTO bookings (booking_ref, user_id, room_id, check_in, check_out, nights, guests_count, status, total_amount, special_requests) VALUES (?, ?, ?, ?, ?, ?, ?, 'pending', ?, ?)");
    $insertBooking->bind_param('siissiiss', $bookingRef, $userId, $roomId, $checkIn, $checkOut, $nights, $guestCount, $roomTotal, $specialRequests);
    $insertBooking->execute();
    $bookingId = $insertBooking->insert_id;

    $insertInvoice = $conn->prepare("INSERT INTO invoices (invoice_ref, booking_id, user_id, room_charges, tax_amount, total_amount, status) VALUES (?, ?, ?, ?, ?, ?, 'issued')");
    $insertInvoice->bind_param('siiddd', $invoiceRef, $bookingId, $userId, $roomTotal, $tax, $invoiceTotal);
    $insertInvoice->execute();
    $conn->commit();
    api_json(true, 'Booking request created.', null, 200, ['booking_ref' => $bookingRef, 'total' => (float)$roomTotal, 'nights' => $nights]);
} catch (DomainException $error) {
    $conn->rollback();
    api_json(false, $error->getMessage(), null, 409);
} catch (Throwable $error) {
    $conn->rollback();
    throw $error;
}
$conn->close();
?>
