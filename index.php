<?php
date_default_timezone_set('Asia/Seoul');

// 1. Basic Auth (hrk 계정 전용)
$currentUser = $_SERVER['REMOTE_USER'] ?? $_SERVER['PHP_AUTH_USER'] ?? '';
if ($currentUser !== 'hrk') {
    header('WWW-Authenticate: Basic realm="Project Center"');
    header('HTTP/1.0 401 Unauthorized');
    exit('Unauthorized');
}

// 2. Supabase Setup (.env)
$_env = parse_ini_file(__DIR__ . '/.env');
$supabaseUrl = $_env['SUPABASE_URL'] ?? '';
$anonKey = $_env['SUPABASE_ANON_KEY'] ?? '';

function fetchSupabase($query) {
    global $supabaseUrl, $anonKey;
    if (empty($supabaseUrl) || empty($anonKey)) return [];
    $url = $supabaseUrl . "/rest/v1/" . $query;
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ["apikey: $anonKey", "Authorization: Bearer $anonKey"]);
    $response = curl_exec($ch);
    if (curl_errno($ch)) { curl_close($ch); return []; }
    curl_close($ch);
    $data = json_decode($response, true);
    return (is_array($data) && !isset($data['code'])) ? $data : [];
}

// 3. Priority & Category Mapping
$priorityMap = [
    '중점' => 1, '이슈' => 2, '미착' => 3, '사업화' => 4, '정보' => 5, '정비' => 6, 'DLE' => 7, '완료' => 8, 'Drop' => 9, 'General' => 10
];

function getPriority($cat) {
    global $priorityMap;
    return $priorityMap[$cat] ?? 99;
}

function parseProjectFile($path, $filename) {
    $content = @file_get_contents($path);
    if ($content === false) return null;
    $id = str_replace('.md', '', $filename);
    $meta = [
        'id' => $id,
        'project_name' => $id,
        'status' => 'Ongoing',
        'category' => 'General',
        'date' => '-',
        'body_raw' => ''
    ];

    if (preg_match('/^---([\s\S]*?)---/', $content, $matches)) {
        $yaml = $matches[1];
        if (preg_match('/project:\s*(.*)/', $yaml, $m)) $meta['project_name'] = trim($m[1]);
        if (preg_match('/type:\s*(.*)/', $yaml, $m)) $meta['category'] = trim($m[1]);
        if (preg_match('/status:\s*(.*)/', $yaml, $m)) $meta['status'] = trim($m[1]);
        if (preg_match('/date:\s*(.*)/', $yaml, $m)) $meta['date'] = trim($m[1]);
        $body = preg_replace('/^---[\s\S]*?---[\s]*/', '', $content);
    } else {
        $body = $content;
    }
    $meta['body_raw'] = trim($body);
    return $meta;
}

// 4. Data Loading
$kbDir = "/home/bitnami/onedrive_sync/Knowledge Base/projects/";
$allProjects = [];
if (is_dir($kbDir)) {
    $files = scandir($kbDir);
    foreach ($files as $f) {
        if (str_ends_with($f, '.md')) {
            $p = parseProjectFile($kbDir . $f, $f);
            if ($p) $allProjects[] = $p;
        }
    }
}

usort($allProjects, function($a, $b) {
    $pA = getPriority($a['category']);
    $pB = getPriority($b['category']);
    if ($pA !== $pB) return $pA - $pB;
    return strcmp($a['project_name'], $b['project_name']);
});

$allCats = array_unique(array_column($allProjects, 'category'));
usort($allCats, function($a, $b) { return getPriority($a) - getPriority($b); });

$selectedId = $_GET['p'] ?? '';
$crms = []; $todos = []; $currentProject = null;
if ($selectedId) {
    foreach ($allProjects as $p) { if ($p['id'] == $selectedId) { $currentProject = $p; break; } }
    if ($currentProject) {
        $pId = rawurlencode(preg_replace('/[^a-zA-Z0-9\x{AC00}-\x{D7A3}_\-\s]/u', '', $selectedId));
        $pName = rawurlencode(preg_replace('/[^a-zA-Z0-9\x{AC00}-\x{D7A3}_\-\s]/u', '', $currentProject['project_name']));

        // 검색 범위 확장 (ID 또는 실제 프로젝트 이름으로 검색)
        $filter = "or=(project_folder.eq.$pId,project.ilike.*$pId*,project.ilike.*$pName*)";
        $crms = fetchSupabase("crm_log?$filter&order=date.desc");
        $todos = fetchSupabase("todo_log?$filter&order=date.desc");
        if (!is_array($crms)) $crms = [];
        if (!is_array($todos)) $todos = [];
    }
}
?>
<!DOCTYPE html>
<html lang="ko">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $currentProject ? htmlspecialchars($currentProject['project_name']) : 'Project Center' ?> | DL건설</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://cdn.jsdelivr.net/npm/marked/marked.min.js"></script>
    <link href="https://fonts.googleapis.com/css2?family=Pretendard:wght@400;600;700;800&display=swap" rel="stylesheet">
    <style>
        body { background: #f8fafc; font-family: 'Pretendard', sans-serif; letter-spacing: -0.01em; }
        .sidebar { height: calc(100vh - 4rem); width: 22rem; min-width: 22rem; transition: all 0.3s ease; }
        .sidebar.collapsed { margin-left: -22rem; opacity: 0; }
        .active-project { background: white; border-color: #3b82f6; box-shadow: 0 10px 15px -3px rgba(59, 130, 246, 0.1); }
        .category-btn.active { background-color: #0C2340; color: white; border-color: #0C2340; }
        .no-scrollbar::-webkit-scrollbar { display: none; }
        .section-content { transition: all 0.4s ease; overflow: hidden; max-height: 50000px; }
        .section-content.collapsed { max-height: 0; opacity: 0; }
        .markdown-body { font-size: 1.05rem; line-height: 1.8; color: #334155; }
        .markdown-body h1 { font-size: 1.8rem; font-weight: 800; color: #0C2340; border-bottom: 3px solid #0C2340; padding-bottom: 0.5rem; margin-top: 2rem; margin-bottom: 1.5rem; }
        .markdown-body h2 { font-size: 1.4rem; font-weight: 700; color: #0C2340; margin-top: 1.5rem; margin-bottom: 1rem; border-left: 5px solid #3b82f6; padding-left: 0.75rem; }
        .markdown-body table { width: 100%; border-collapse: separate; border-spacing: 0; margin: 1.5rem 0; border: 1px solid #e2e8f0; border-radius: 0.75rem; overflow: hidden; }
        .markdown-body th { background-color: #f8fafc; color: #0C2340; font-weight: 700; padding: 0.75rem 1rem; border-bottom: 1px solid #e2e8f0; text-align: left; }
        .markdown-body td { padding: 0.75rem 1rem; border-bottom: 1px solid #f1f5f9; font-size: 0.95rem; background: white; }
        .markdown-body ul { list-style-type: disc; margin-left: 1.5rem; margin-bottom: 1rem; }
        .crm-markdown { font-size: 0.9rem; line-height: 1.6; }
        .editable:hover { background-color: #f0f9ff; cursor: pointer; border-radius: 4px; }
    </style>
</head>
<body class="overflow-hidden">
    <nav class="h-16 bg-[#0C2340] text-white flex items-center px-6 justify-between shadow-xl z-50 relative">
        <div class="flex items-center gap-4">
            <button onclick="toggleSidebar()" class="p-2 hover:bg-white/10 rounded-lg"><svg xmlns="http://www.w3.org/2000/svg" class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16" /></svg></button>
            <span class="text-2xl font-bold">&#127959;</span>
            <a href="/ProjectCenter/" class="hover:opacity-80 transition-opacity"><h1 class="text-lg font-black tracking-tighter uppercase">Project Center</h1></a>
        </div>
        <div class="flex items-center gap-4 text-[10px] font-bold text-green-400 font-mono tracking-widest"><span class="animate-pulse">&#9679;</span> Realtime SSOT</div>
    </nav>

    <div class="flex overflow-hidden">
        <aside id="sidebar" class="sidebar border-r bg-slate-50/50 flex flex-col overflow-hidden">
            <div class="p-5 border-b bg-white space-y-4">
                <input type="text" id="project-search" oninput="filterProjects()" placeholder="프로젝트 검색..." class="w-full px-4 py-2 bg-slate-100 border-none rounded-xl text-xs focus:ring-2 focus:ring-blue-500">
                <div class="flex gap-1.5 overflow-x-auto pb-1 no-scrollbar flex-wrap">
                    <button id="cat-all" onclick="selectCategory('All')" class="category-btn active px-3 py-1 rounded-lg text-[10px] font-bold border border-slate-200">전체</button>
                    <?php foreach ($allCats as $cat): ?>
                        <button onclick="selectCategory('<?= htmlspecialchars($cat) ?>')" class="category-btn px-3 py-1 rounded-lg text-[10px] font-bold border border-slate-200 bg-white text-slate-500" data-cat="<?= htmlspecialchars($cat) ?>"><?= htmlspecialchars($cat) ?></button>
                    <?php endforeach; ?>
                </div>
            </div>
            <div class="flex-1 overflow-y-auto p-3 space-y-2" id="project-list">
                <?php foreach ($allProjects as $p): ?>
                    <a href="?p=<?= urlencode($p['id']) ?>" data-id="<?= htmlspecialchars($p['id']) ?>" data-name="<?= htmlspecialchars($p['project_name']) ?>" data-category="<?= htmlspecialchars($p['category']) ?>"
                       class="project-item block p-4 rounded-xl border-2 transition-all <?= ($selectedId == $p['id']) ? 'active-project border-blue-500 scale-[1.01]' : 'bg-white border-transparent' ?>">
                        <div class="flex justify-between items-start mb-1 text-[9px] uppercase font-black">
                            <span class="text-blue-500 tracking-tighter"><?= htmlspecialchars($p['category']) ?></span>
                            <span class="text-slate-300"><?= htmlspecialchars($p['status']) ?></span>
                        </div>
                        <div class="font-extrabold text-sm text-slate-800 leading-tight"><?= htmlspecialchars($p['project_name']) ?></div>
                    </a>
                <?php endforeach; ?>
            </div>
        </aside>

        <main id="main-content" class="flex-1 overflow-y-auto p-8 lg:p-12 sidebar">
            <?php if (!$currentProject): ?>
                <div class="h-full flex flex-col items-center justify-center text-center opacity-30">
                    <div class="w-24 h-24 bg-white rounded-3xl shadow-xl flex items-center justify-center text-4xl mb-6 text-slate-300">&#127970;</div>
                    <h2 class="text-xl font-bold text-slate-800">통합 프로젝트 정보 센터</h2>
                    <p class="text-xs text-slate-400 mt-2 italic">좌측 목록에서 프로젝트를 선택하세요.</p>
                </div>
            <?php else: ?>
                <div class="max-w-5xl mx-auto space-y-12 pb-32">
                    <header>
                        <div class="flex items-center gap-2 mb-4 text-[10px] font-bold tracking-widest uppercase">
                            <span class="bg-blue-600 text-white px-3 py-1 rounded-full"><?= htmlspecialchars($currentProject['category']) ?></span>
                            <span class="text-slate-300">/</span>
                            <span class="text-slate-400"><?= htmlspecialchars($currentProject['id']) ?></span>
                        </div>
                        <h2 class="text-5xl font-black text-slate-900 leading-tight tracking-tighter"><?= htmlspecialchars($currentProject['project_name']) ?></h2>
                    </header>

                    <!-- Section: TODO -->
                    <section class="border-t border-slate-200 pt-10">
                        <div onclick="toggleSection('todo')" class="flex items-center justify-between cursor-pointer group mb-6">
                            <h3 class="text-xl font-black text-slate-800 flex items-center gap-3">현 시점 중요 할 일 <span class="text-sm font-normal text-slate-400 bg-slate-100 px-2 py-0.5 rounded-lg"><?= count(array_filter($todos, fn($t) => ($t['status'] ?? '') != 'Done')) ?></span></h3>
                            <svg id="chevron-todo" class="h-5 w-5 text-slate-300 transition-transform duration-300" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7" /></svg>
                        </div>
                        <div id="content-todo" class="section-content grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
                            <?php foreach ($todos as $t): if (($t['status'] ?? '') == 'Done') continue; ?>
                                <div class="bg-white p-5 rounded-2xl border border-slate-200 shadow-sm relative group">
                                    <div class="flex justify-between items-start mb-3">
                                        <span class="text-[9px] font-black <?= (($t['priority'] ?? '') == 'High') ? 'text-red-500' : 'text-slate-400' ?> uppercase tracking-widest"><?= htmlspecialchars((string)($t['priority'] ?? 'Normal')) ?> Priority</span>
                                        <span class="text-[9px] text-slate-300 font-bold"><?= htmlspecialchars((string)($t['date'] ?? '-')) ?></span>
                                    </div>
                                    <div class="text-sm font-bold text-slate-700 leading-snug mb-4 editable" onclick="editItem('todo', '<?= htmlspecialchars((string)($t['id'] ?? '')) ?>', this, 'task')"><?= htmlspecialchars((string)($t['task'] ?? '')) ?></div>
                                    <div class="flex items-center justify-between pt-3 border-t border-slate-50 text-[10px] font-bold">
                                        <div class="flex items-center gap-1.5 text-slate-500">&#128100; <span class="editable" onclick="editItem('todo', '<?= htmlspecialchars((string)($t['id'] ?? '')) ?>', this, 'assignee')"><?= htmlspecialchars((string)($t['assignee'] ?? '미지정')) ?></span></div>
                                        <div class="flex items-center gap-1.5 text-blue-500">&#128197; <span class="editable" onclick="editItem('todo', '<?= htmlspecialchars((string)($t['id'] ?? '')) ?>', this, 'due_date')"><?= htmlspecialchars((string)($t['due_date'] ?? '기한무')) ?></span></div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </section>

                    <!-- Section: CRM -->
                    <section class="border-t border-slate-200 pt-10">
                        <div onclick="toggleSection('crm')" class="flex items-center justify-between cursor-pointer group mb-8">
                            <h3 class="text-xl font-black text-slate-800 flex items-center gap-3">핵심 미팅 및 활동 로그 <span class="text-sm font-normal text-slate-400 bg-slate-100 px-2 py-0.5 rounded-lg"><?= count($crms) ?></span></h3>
                            <svg id="chevron-crm" class="h-5 w-5 text-slate-300 transition-transform duration-300" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7" /></svg>
                        </div>
                        <div id="content-crm" class="section-content space-y-10">
                            <?php foreach ($crms as $c): ?>
                                <div class="bg-white p-8 rounded-[2.5rem] border border-slate-100 shadow-lg relative group border-l-[12px] border-l-indigo-500">
                                    <div class="absolute top-8 right-8 flex gap-2 edit-btn">
                                        <button onclick="editWikiCRM('<?= htmlspecialchars((string)($c['id'] ?? '')) ?>')" class="p-2.5 bg-slate-50 rounded-xl hover:bg-blue-50 text-blue-500"><svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z" /></svg></button>
                                    </div>
                                    <div class="flex items-center gap-3 text-xs font-black text-indigo-500 uppercase tracking-widest mb-6">
                                        <span><?= htmlspecialchars((string)($c['date'] ?? '-')) ?></span>
                                        <span class="w-1.5 h-1.5 bg-slate-200 rounded-full"></span>
                                        <span class="bg-indigo-50 px-2 py-0.5 rounded text-indigo-600"><?= htmlspecialchars((string)($c['type'] ?? 'Log')) ?></span>
                                    </div>
                                    <div class="text-3xl font-black text-slate-900 mb-4 leading-tight"><?= htmlspecialchars((string)($c['title'] ?? $c['summary'] ?? 'Untitled')) ?></div>
                                    <div id="crm-raw-<?= htmlspecialchars((string)($c['id'] ?? '')) ?>" class="hidden"><?= htmlspecialchars((string)($c['raw_text'] ?? $c['content'] ?? '')) ?></div>
                                    <div id="crm-body-<?= htmlspecialchars((string)($c['id'] ?? '')) ?>" class="crm-markdown bg-slate-50/30 p-8 rounded-3xl border border-slate-50 text-slate-700"></div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </section>

                    <!-- Section: Wiki -->
                    <section class="bg-white p-10 lg:p-14 rounded-[3.5rem] shadow-2xl border border-slate-100 relative">
                        <div class="flex items-center justify-between mb-10">
                            <h3 class="text-2xl font-black text-slate-800 tracking-tighter">프로젝트 현황 (Wiki)</h3>
                            <div class="flex items-center gap-3">
                                <button id="btn-wiki-edit" onclick="toggleWikiEdit()" class="px-5 py-2.5 bg-slate-100 hover:bg-blue-600 hover:text-white rounded-xl text-[11px] font-black transition-all">편집 모드</button>
                                <button id="btn-wiki-save" onclick="saveWiki()" class="hidden px-5 py-2.5 bg-blue-600 text-white rounded-xl text-[11px] font-black shadow-lg">저장</button>
                                <div class="flex gap-4 text-[10px] font-bold text-slate-400 uppercase tracking-widest ml-4">
                                    <span>Update: <?= htmlspecialchars((string)($currentProject['date'] ?? '-')) ?></span>
                                    <span>Status: <span class="text-blue-600"><?= htmlspecialchars((string)($currentProject['status'] ?? '-')) ?></span></span>
                                </div>
                            </div>
                        </div>
                        <div id="markdown-raw" class="hidden"><?= htmlspecialchars((string)($currentProject['body_raw'] ?? '')) ?></div>
                        <div id="wiki-display" class="markdown-body"></div>
                        <textarea id="wiki-editor" class="hidden w-full min-h-[600px] p-8 bg-slate-50 rounded-3xl font-mono text-sm border-2 border-blue-100 focus:border-blue-500 outline-none"></textarea>
                    </section>
                </div>
            <?php endif; ?>
        </main>
    </div>

    <!-- CRM Modal -->
    <div id="crm-modal" class="fixed inset-0 bg-slate-900/60 z-[100] hidden flex items-center justify-center p-6 backdrop-blur-sm">
        <div class="bg-white w-full max-w-4xl rounded-[3rem] shadow-2xl p-10 flex flex-col max-h-[90vh]">
            <div class="flex justify-between items-center mb-8"><h3 class="text-2xl font-black text-slate-800">로그 내용 편집</h3><button onclick="closeCRMModal()" class="text-slate-400"><svg xmlns="http://www.w3.org/2000/svg" class="h-8 w-8" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" /></svg></button></div>
            <textarea id="crm-editor" class="flex-1 w-full p-8 bg-slate-50 rounded-3xl font-mono text-sm border-2 border-blue-100 focus:border-blue-500 outline-none mb-8"></textarea>
            <div class="flex justify-end gap-4"><button onclick="closeCRMModal()" class="px-8 py-3 bg-slate-100 rounded-2xl font-black text-sm">취소</button><button id="btn-crm-save" class="px-8 py-3 bg-blue-600 text-white rounded-2xl font-black text-sm shadow-xl">완료</button></div>
        </div>
    </div>

    <script>
        function toggleSidebar() { document.getElementById('sidebar').classList.toggle('collapsed'); }
        function toggleSection(sid) {
            const c = document.getElementById('content-' + sid), v = document.getElementById('chevron-' + sid);
            c.classList.toggle('collapsed');
            v.style.transform = c.classList.contains('collapsed') ? 'rotate(-90deg)' : 'rotate(0deg)';
        }

        async function editItem(type, id, el, field) {
            if (el.querySelector('input')) return;
            const original = el.innerText.trim();
            const input = document.createElement('input');
            input.value = (original === '미지정' || original === '기한무') ? '' : original;
            input.className = 'w-full px-2 py-1 border-2 border-blue-500 rounded font-bold';
            el.innerHTML = ''; el.appendChild(input); input.focus();
            input.onblur = () => el.innerText = original;
            input.onkeydown = async (e) => {
                if (e.key === 'Enter') {
                    const content = input.value;
                    const resp = await fetch('api_update.php', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ type, id, content, field }) });
                    if ((await resp.json()).success) el.innerText = content || (field === 'assignee' ? '미지정' : '기한무');
                    else el.innerText = original;
                }
            };
        }

        function editWikiCRM(id) {
            const raw = document.getElementById('crm-raw-' + id).textContent;
            document.getElementById('crm-editor').value = raw;
            document.getElementById('crm-modal').classList.remove('hidden');
            document.getElementById('btn-crm-save').onclick = async () => {
                const content = document.getElementById('crm-editor').value;
                const resp = await fetch('api_update.php', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ type: 'crm', id, content, field: 'raw_text' }) });
                if ((await resp.json()).success) {
                    document.getElementById('crm-raw-' + id).textContent = content;
                    document.getElementById('crm-body-' + id).innerHTML = marked.parse(content);
                    closeCRMModal();
                }
            };
        }
        function closeCRMModal() { document.getElementById('crm-modal').classList.add('hidden'); }

        function toggleWikiEdit() {
            const d = document.getElementById('wiki-display'), e = document.getElementById('wiki-editor'), s = document.getElementById('btn-wiki-save'), t = document.getElementById('btn-wiki-edit');
            const editing = d.classList.toggle('hidden');
            e.classList.toggle('hidden'); s.classList.toggle('hidden');
            if (editing) e.value = document.getElementById('markdown-raw').textContent;
            t.innerText = editing ? '취소' : '편집 모드';
        }

        async function saveWiki() {
            const content = document.getElementById('wiki-editor').value;
            const resp = await fetch('api_update.php', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ type: 'wiki', id: '<?= urlencode($selectedId) ?>', content }) });
            if ((await resp.json()).success) {
                document.getElementById('markdown-raw').textContent = content;
                document.getElementById('wiki-display').innerHTML = marked.parse(content);
                toggleWikiEdit();
            }
        }

        function selectCategory(cat) {
            document.querySelectorAll('.project-item').forEach(p => {
                p.style.display = (cat === 'All' || p.dataset.category === cat) ? 'block' : 'none';
            });
            document.querySelectorAll('.category-btn').forEach(b => b.classList.toggle('active', b.innerText === (cat === 'All' ? '전체' : cat)));
        }

        function filterProjects() {
            const q = document.getElementById('project-search').value.toLowerCase();
            document.querySelectorAll('.project-item').forEach(p => {
                p.style.display = (p.dataset.name.toLowerCase().includes(q) || p.dataset.id.toLowerCase().includes(q)) ? 'block' : 'none';
            });
        }

        window.onload = function() {
            marked.setOptions({ gfm: true, breaks: true });
            const raw = document.getElementById('markdown-raw'), disp = document.getElementById('wiki-display');
            if (raw && disp) disp.innerHTML = marked.parse(raw.textContent);
            document.querySelectorAll('[id^="crm-body-"]').forEach(el => {
                const id = el.id.replace('crm-body-', '');
                const rawEl = document.getElementById('crm-raw-' + id);
                if (rawEl) el.innerHTML = marked.parse(rawEl.textContent);
            });
        };
    </script>
</body>
</html>
