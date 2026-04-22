<?php
date_default_timezone_set('Asia/Seoul');

// 1. 인증 확인
$currentUser = $_SERVER['REMOTE_USER'] ?? $_SERVER['PHP_AUTH_USER'] ?? '';
if ($currentUser !== 'hrk') {
    http_response_code(401);
    exit(json_encode(['error' => 'Unauthorized']));
}

// 2. 요청 파싱
$data = json_decode(file_get_contents('php://input'), true);
$type = $data['type'] ?? '';
$id = $data['id'] ?? '';
$content = $data['content'] ?? '';

// 3. 환경변수 로드
$_env = parse_ini_file(__DIR__ . '/.env');
$supabaseUrl = $_env['SUPABASE_URL'] ?? '';
$serviceRoleKey = $_env['SUPABASE_SERVICE_KEY'] ?? '';

if ($type === 'wiki') {
    // 마크다운 파일 수정 (Path Traversal 방어)
    $safeId = basename($id);
    if ($safeId !== $id || !preg_match('/^[a-zA-Z0-9_\-]+$/', $safeId)) {
        http_response_code(400);
        exit(json_encode(['error' => 'Invalid project ID']));
    }
    $kbPath = "/home/bitnami/onedrive_sync/Knowledge Base/projects/" . $safeId . ".md";
    if (file_exists($kbPath)) {
        $oldContent = file_get_contents($kbPath);
        if (preg_match('/^---[\s\S]*?---/', $oldContent, $matches)) {
            $newContent = $matches[0] . "\n\n" . $content;
            file_put_contents($kbPath, $newContent);
            echo json_encode(['success' => true]);
        } else {
            file_put_contents($kbPath, $content);
            echo json_encode(['success' => true]);
        }
    } else {
        http_response_code(404);
        echo json_encode(['error' => 'File not found']);
    }
} else {
    // Supabase DB 수정 (TODO 또는 CRM)
    $table = ($type === 'todo') ? 'todo_log' : 'crm_log';
    $field = ($type === 'todo') ? 'task' : 'summary';

    if ($type === 'crm' && isset($data['field'])) $field = $data['field'];
    if ($type === 'todo' && isset($data['field'])) $field = $data['field'];

    $url = "$supabaseUrl/rest/v1/$table?id=eq.$id";
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "PATCH");
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode([$field => $content]));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        "apikey: $serviceRoleKey",
        "Authorization: Bearer $serviceRoleKey",
        "Content-Type: application/json",
        "Prefer: return=minimal"
    ]);
    $response = curl_exec($ch);
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($status >= 200 && $status < 300) {
        echo json_encode(['success' => true]);
    } else {
        http_response_code(500);
        echo json_encode(['error' => 'DB Update Failed', 'status' => $status]);
    }
}
?>
