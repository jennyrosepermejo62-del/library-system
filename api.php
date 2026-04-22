<?php
// api.php — Full REST API v2.0
// FIXED: require_once auth.php (safe because auth.php guards its top-level code)
// FIXED: all endpoints gated by requireAuth()
// FIXED: member_id correctly read from session user

require_once 'db.php';
require_once 'auth.php';

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, X-Auth-Token, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(200); exit; }

$pdo         = getDBConnection();
$method      = $_SERVER['REQUEST_METHOD'];
$resource    = $_GET['resource'] ?? '';
$id          = isset($_GET['id']) ? (int)$_GET['id'] : null;

$currentUser = requireAuth($pdo);
$isAdmin     = ($currentUser['role'] === 'admin');
$memberId    = $currentUser['member_id'] ? (int)$currentUser['member_id'] : null;

switch ($resource) {

    case 'books':
        switch ($method) {
            case 'GET':  $id ? getBook($pdo,$id,$isAdmin,$memberId) : getBooks($pdo,$isAdmin); break;
            case 'POST': adminOnly($isAdmin); createBook($pdo); break;
            case 'PUT':  adminOnly($isAdmin); updateBook($pdo,$id); break;
            case 'DELETE': adminOnly($isAdmin); deleteBook($pdo,$id); break;
        }
        break;

    case 'members':
        adminOnly($isAdmin);
        switch ($method) {
            case 'GET':  $id ? getMember($pdo,$id) : getMembers($pdo); break;
            case 'POST': createMember($pdo); break;
            case 'PUT':  updateMember($pdo,$id); break;
            case 'DELETE': deleteMember($pdo,$id); break;
        }
        break;

    case 'borrowings':
        switch ($method) {
            case 'GET':    $isAdmin ? getBorrowings($pdo) : getMemberBorrowings($pdo,$memberId); break;
            case 'POST':   adminOnly($isAdmin); createBorrowing($pdo); break;
            case 'PUT':    adminOnly($isAdmin); returnBook($pdo,$id); break;
            case 'DELETE': adminOnly($isAdmin); deleteBorrowing($pdo,$id); break;
        }
        break;

    case 'search':
        searchOpenLibrary();
        break;

    case 'stats':
        adminOnly($isAdmin);
        getDashboardStats($pdo);
        break;

    case 'reviews':
        switch ($method) {
            case 'GET':    getBookReviews($pdo,$id); break;
            case 'POST':   submitReview($pdo,$memberId,$isAdmin); break;
            case 'DELETE': deleteReview($pdo,$id,$memberId,$isAdmin); break;
        }
        break;

    case 'reading-list':
        switch ($method) {
            case 'GET':    getReadingList($pdo,$memberId,$isAdmin); break;
            case 'POST':   addToReadingList($pdo,$memberId); break;
            case 'DELETE': removeFromReadingList($pdo,$id,$memberId); break;
        }
        break;

    case 'member-stats':
        if (!$memberId) jsonResponse(['success'=>false,'message'=>'Not a member account'], 403);
        getMemberStats($pdo,$memberId);
        break;

    default:
        jsonResponse(['success'=>false,'message'=>'Unknown resource: '.$resource], 404);
}

// ── Helper ─────────────────────────────────────────────────────────────────────
function adminOnly(bool $isAdmin): void {
    if (!$isAdmin) jsonResponse(['success'=>false,'message'=>'Admin only'], 403);
}

// ── BOOKS ──────────────────────────────────────────────────────────────────────
function getBooks(PDO $pdo, bool $isAdmin): void {
    $search   = $_GET['search']   ?? '';
    $category = $_GET['category'] ?? '';
    $status   = $_GET['status']   ?? '';

    $sql    = "SELECT b.*, COALESCE(AVG(r.rating),0) AS avg_rating, COUNT(r.id) AS review_count
               FROM books b LEFT JOIN book_reviews r ON b.id=r.book_id WHERE 1=1";
    $params = [];

    if ($search)   { $sql .= " AND (b.title LIKE ? OR b.author LIKE ? OR b.isbn LIKE ?)"; $params=array_merge($params,["%$search%","%$search%","%$search%"]); }
    if ($category) { $sql .= " AND b.category=?"; $params[]=$category; }
    if ($status)   { $sql .= " AND b.status=?";   $params[]=$status; }

    $sql .= " GROUP BY b.id ORDER BY b.date_added DESC";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $books = $stmt->fetchAll();

    if (!$isAdmin) {
        foreach ($books as &$b) {
            if (!empty($b['first_page_excerpt']))
                $b['excerpt_preview'] = mb_substr($b['first_page_excerpt'], 0, 120).'…';
            unset($b['first_page_excerpt']);
        }
        unset($b);
    }

    jsonResponse(['success'=>true,'data'=>$books,'count'=>count($books)]);
}

function getBook(PDO $pdo, int $id, bool $isAdmin, ?int $memberId): void {
    $stmt = $pdo->prepare("SELECT b.*, COALESCE(AVG(r.rating),0) AS avg_rating, COUNT(r.id) AS review_count
        FROM books b LEFT JOIN book_reviews r ON b.id=r.book_id WHERE b.id=? GROUP BY b.id");
    $stmt->execute([$id]);
    $book = $stmt->fetch();
    if (!$book) jsonResponse(['success'=>false,'message'=>'Book not found'], 404);

    if ($memberId) {
        $s = $pdo->prepare("SELECT id FROM reading_list WHERE member_id=? AND book_id=?");
        $s->execute([$memberId,$id]);
        $book['in_reading_list'] = (bool)$s->fetchColumn();

        $s2 = $pdo->prepare("SELECT rating, review_text FROM book_reviews WHERE member_id=? AND book_id=?");
        $s2->execute([$memberId,$id]);
        $book['my_review'] = $s2->fetch() ?: null;
    }

    $s3 = $pdo->prepare("SELECT r.*, m.name AS member_name FROM book_reviews r JOIN members m ON r.member_id=m.id WHERE r.book_id=? ORDER BY r.created_at DESC");
    $s3->execute([$id]);
    $book['reviews'] = $s3->fetchAll();

    jsonResponse(['success'=>true,'data'=>$book]);
}

function createBook(PDO $pdo): void {
    $b = getRequestBody();
    if (empty($b['title']) || empty($b['author']))
        jsonResponse(['success'=>false,'message'=>'Title and author are required'], 400);

    $stmt = $pdo->prepare("INSERT INTO books (title,author,isbn,cover_url,open_library_key,category,year_published,description,first_page_excerpt,total_pages,language,publisher,status)
        VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)");
    $stmt->execute([
        trim($b['title']), trim($b['author']),
        $b['isbn']??null, $b['cover_url']??null, $b['open_library_key']??null,
        $b['category']??null, !empty($b['year_published'])?(int)$b['year_published']:null,
        $b['description']??null, $b['first_page_excerpt']??null,
        !empty($b['total_pages'])?(int)$b['total_pages']:null,
        $b['language']??'English', $b['publisher']??null, $b['status']??'available',
    ]);
    jsonResponse(['success'=>true,'message'=>'Book added successfully','id'=>(int)$pdo->lastInsertId()], 201);
}

function updateBook(PDO $pdo, ?int $id): void {
    if (!$id) jsonResponse(['success'=>false,'message'=>'ID required'], 400);
    $b = getRequestBody();
    $stmt = $pdo->prepare("UPDATE books SET title=?,author=?,isbn=?,cover_url=?,open_library_key=?,category=?,year_published=?,description=?,first_page_excerpt=?,total_pages=?,language=?,publisher=?,status=? WHERE id=?");
    $stmt->execute([
        trim($b['title']??''), trim($b['author']??''),
        $b['isbn']??null, $b['cover_url']??null, $b['open_library_key']??null,
        $b['category']??null, !empty($b['year_published'])?(int)$b['year_published']:null,
        $b['description']??null, $b['first_page_excerpt']??null,
        !empty($b['total_pages'])?(int)$b['total_pages']:null,
        $b['language']??'English', $b['publisher']??null, $b['status']??'available', $id,
    ]);
    jsonResponse(['success'=>true,'message'=>'Book updated successfully']);
}

function deleteBook(PDO $pdo, ?int $id): void {
    if (!$id) jsonResponse(['success'=>false,'message'=>'ID required'], 400);
    $chk = $pdo->prepare("SELECT COUNT(*) FROM borrowings WHERE book_id=? AND status='active'");
    $chk->execute([$id]);
    if ($chk->fetchColumn() > 0) jsonResponse(['success'=>false,'message'=>'Cannot delete book with active borrowings'], 409);
    $pdo->prepare("DELETE FROM books WHERE id=?")->execute([$id]);
    jsonResponse(['success'=>true,'message'=>'Book deleted']);
}

// ── MEMBERS ────────────────────────────────────────────────────────────────────
function getMembers(PDO $pdo): void {
    $search = $_GET['search'] ?? '';
    $sql    = "SELECT * FROM members WHERE 1=1";
    $params = [];
    if ($search) { $sql .= " AND (name LIKE ? OR email LIKE ? OR phone LIKE ?)"; $params=["%$search%","%$search%","%$search%"]; }
    $sql .= " ORDER BY date_registered DESC";
    $stmt = $pdo->prepare($sql); $stmt->execute($params);
    jsonResponse(['success'=>true,'data'=>$stmt->fetchAll()]);
}

function getMember(PDO $pdo, int $id): void {
    $stmt = $pdo->prepare("SELECT * FROM members WHERE id=?"); $stmt->execute([$id]);
    $m = $stmt->fetch();
    if (!$m) jsonResponse(['success'=>false,'message'=>'Member not found'], 404);
    jsonResponse(['success'=>true,'data'=>$m]);
}

function createMember(PDO $pdo): void {
    $b = getRequestBody();
    if (empty($b['name']) || empty($b['email'])) jsonResponse(['success'=>false,'message'=>'Name and email required'], 400);
    $chk = $pdo->prepare("SELECT id FROM members WHERE email=?"); $chk->execute([$b['email']]);
    if ($chk->fetch()) jsonResponse(['success'=>false,'message'=>'Email already exists'], 409);

    $stmt = $pdo->prepare("INSERT INTO members (name,email,phone,address,membership_type,status) VALUES (?,?,?,?,?,?)");
    $stmt->execute([trim($b['name']),trim($b['email']),$b['phone']??null,$b['address']??null,$b['membership_type']??'standard',$b['status']??'active']);
    $newId = (int)$pdo->lastInsertId();

    // Auto-create login account
    $username = strtolower(preg_replace('/[^a-z0-9.]/','',str_replace(' ','.',trim($b['name']))));
    $passHash = password_hash('Member@1234', PASSWORD_BCRYPT);
    try {
        $pdo->prepare("INSERT INTO users (username,email,password_hash,role,member_id) VALUES (?,?,?,'member',?)")
            ->execute([$username,$b['email'],$passHash,$newId]);
    } catch (PDOException $e) {
        $username .= $newId;
        $pdo->prepare("INSERT INTO users (username,email,password_hash,role,member_id) VALUES (?,?,?,'member',?)")
            ->execute([$username,$b['email'],$passHash,$newId]);
    }

    jsonResponse(['success'=>true,'message'=>'Member registered','id'=>$newId,'login'=>['username'=>$username,'default_password'=>'Member@1234']], 201);
}

function updateMember(PDO $pdo, ?int $id): void {
    if (!$id) jsonResponse(['success'=>false,'message'=>'ID required'], 400);
    $b = getRequestBody();
    $pdo->prepare("UPDATE members SET name=?,email=?,phone=?,address=?,membership_type=?,status=? WHERE id=?")
        ->execute([trim($b['name']??''),trim($b['email']??''),$b['phone']??null,$b['address']??null,$b['membership_type']??'standard',$b['status']??'active',$id]);
    jsonResponse(['success'=>true,'message'=>'Member updated']);
}

function deleteMember(PDO $pdo, ?int $id): void {
    if (!$id) jsonResponse(['success'=>false,'message'=>'ID required'], 400);
    $chk = $pdo->prepare("SELECT COUNT(*) FROM borrowings WHERE member_id=? AND status='active'"); $chk->execute([$id]);
    if ($chk->fetchColumn() > 0) jsonResponse(['success'=>false,'message'=>'Cannot delete member with active borrowings'], 409);
    $pdo->prepare("DELETE FROM members WHERE id=?")->execute([$id]);
    jsonResponse(['success'=>true,'message'=>'Member deleted']);
}

// ── BORROWINGS ─────────────────────────────────────────────────────────────────
function getBorrowings(PDO $pdo): void {
    $status = $_GET['status'] ?? '';
    $sql = "SELECT b.*, bk.title AS book_title, bk.author AS book_author, bk.cover_url, m.name AS member_name, m.email AS member_email
            FROM borrowings b JOIN books bk ON b.book_id=bk.id JOIN members m ON b.member_id=m.id WHERE 1=1";
    $params = [];
    if ($status && $status !== 'overdue') { $sql .= " AND b.status=?"; $params[]=$status; }
    $sql .= " ORDER BY b.created_at DESC";
    $stmt = $pdo->prepare($sql); $stmt->execute($params);
    $rows = $stmt->fetchAll();
    foreach ($rows as &$row) {
        if ($row['status']==='active' && strtotime($row['due_date']) < strtotime('today')) $row['status']='overdue';
    } unset($row);
    if ($status==='overdue') $rows = array_values(array_filter($rows, fn($r)=>$r['status']==='overdue'));
    jsonResponse(['success'=>true,'data'=>$rows,'count'=>count($rows)]);
}

function getMemberBorrowings(PDO $pdo, ?int $memberId): void {
    if (!$memberId) jsonResponse(['success'=>false,'message'=>'No member linked'], 403);
    $stmt = $pdo->prepare("SELECT b.*, bk.title AS book_title, bk.author AS book_author, bk.cover_url
        FROM borrowings b JOIN books bk ON b.book_id=bk.id WHERE b.member_id=? ORDER BY b.created_at DESC");
    $stmt->execute([$memberId]);
    $rows = $stmt->fetchAll();
    foreach ($rows as &$row) {
        if ($row['status']==='active' && strtotime($row['due_date']) < strtotime('today')) $row['status']='overdue';
    } unset($row);
    jsonResponse(['success'=>true,'data'=>$rows,'count'=>count($rows)]);
}

function createBorrowing(PDO $pdo): void {
    $b = getRequestBody();
    if (empty($b['book_id']) || empty($b['member_id'])) jsonResponse(['success'=>false,'message'=>'book_id and member_id required'], 400);
    $chk = $pdo->prepare("SELECT status FROM books WHERE id=?"); $chk->execute([$b['book_id']]);
    $book = $chk->fetch();
    if (!$book) jsonResponse(['success'=>false,'message'=>'Book not found'], 404);
    if ($book['status'] !== 'available') jsonResponse(['success'=>false,'message'=>'Book not available'], 409);
    $dueDate = $b['due_date'] ?? date('Y-m-d', strtotime('+14 days'));
    $pdo->prepare("INSERT INTO borrowings (book_id,member_id,borrow_date,due_date,status,notes) VALUES (?,?,CURDATE(),?,'active',?)")
        ->execute([(int)$b['book_id'],(int)$b['member_id'],$dueDate,$b['notes']??null]);
    $pdo->prepare("UPDATE books SET status='borrowed', times_borrowed=times_borrowed+1 WHERE id=?")->execute([$b['book_id']]);
    jsonResponse(['success'=>true,'message'=>'Book borrowed successfully','id'=>(int)$pdo->lastInsertId()], 201);
}

function returnBook(PDO $pdo, ?int $id): void {
    if (!$id) jsonResponse(['success'=>false,'message'=>'ID required'], 400);
    $stmt = $pdo->prepare("SELECT * FROM borrowings WHERE id=?"); $stmt->execute([$id]);
    $row = $stmt->fetch();
    if (!$row) jsonResponse(['success'=>false,'message'=>'Record not found'], 404);
    $pdo->prepare("UPDATE borrowings SET status='returned', return_date=CURDATE() WHERE id=?")->execute([$id]);
    $pdo->prepare("UPDATE books SET status='available' WHERE id=?")->execute([$row['book_id']]);
    jsonResponse(['success'=>true,'message'=>'Book returned']);
}

function deleteBorrowing(PDO $pdo, ?int $id): void {
    if (!$id) jsonResponse(['success'=>false,'message'=>'ID required'], 400);
    $stmt = $pdo->prepare("SELECT * FROM borrowings WHERE id=?"); $stmt->execute([$id]);
    $row = $stmt->fetch();
    if (!$row) jsonResponse(['success'=>false,'message'=>'Record not found'], 404);
    $pdo->prepare("DELETE FROM borrowings WHERE id=?")->execute([$id]);
    if ($row['status']==='active') $pdo->prepare("UPDATE books SET status='available' WHERE id=?")->execute([$row['book_id']]);
    jsonResponse(['success'=>true,'message'=>'Record deleted']);
}

// ── REVIEWS ────────────────────────────────────────────────────────────────────
function getBookReviews(PDO $pdo, ?int $bookId): void {
    if (!$bookId) jsonResponse(['success'=>false,'message'=>'Book ID required'], 400);
    $stmt = $pdo->prepare("SELECT r.*, m.name AS member_name FROM book_reviews r JOIN members m ON r.member_id=m.id WHERE r.book_id=? ORDER BY r.created_at DESC");
    $stmt->execute([$bookId]);
    jsonResponse(['success'=>true,'data'=>$stmt->fetchAll()]);
}

function submitReview(PDO $pdo, ?int $memberId, bool $isAdmin): void {
    if (!$memberId && !$isAdmin) jsonResponse(['success'=>false,'message'=>'Members only'], 403);
    $b      = getRequestBody();
    $bookId = (int)($b['book_id']??0);
    $rating = (int)($b['rating']??0);
    $text   = trim($b['review_text']??'');
    $mId    = $isAdmin ? (int)($b['member_id']??$memberId) : $memberId;
    if (!$bookId || $rating < 1 || $rating > 5) jsonResponse(['success'=>false,'message'=>'Valid book_id and rating 1-5 required'], 400);
    $pdo->prepare("INSERT INTO book_reviews (book_id,member_id,rating,review_text) VALUES (?,?,?,?) ON DUPLICATE KEY UPDATE rating=?,review_text=?")
        ->execute([$bookId,$mId,$rating,$text?:null,$rating,$text?:null]);
    jsonResponse(['success'=>true,'message'=>'Review submitted']);
}

function deleteReview(PDO $pdo, ?int $id, ?int $memberId, bool $isAdmin): void {
    if (!$id) jsonResponse(['success'=>false,'message'=>'ID required'], 400);
    $stmt = $pdo->prepare("SELECT * FROM book_reviews WHERE id=?"); $stmt->execute([$id]);
    $rev = $stmt->fetch();
    if (!$rev) jsonResponse(['success'=>false,'message'=>'Review not found'], 404);
    if (!$isAdmin && $rev['member_id'] !== $memberId) jsonResponse(['success'=>false,'message'=>'Cannot delete another member\'s review'], 403);
    $pdo->prepare("DELETE FROM book_reviews WHERE id=?")->execute([$id]);
    jsonResponse(['success'=>true,'message'=>'Review deleted']);
}

// ── READING LIST ───────────────────────────────────────────────────────────────
function getReadingList(PDO $pdo, ?int $memberId, bool $isAdmin): void {
    $mid = $isAdmin ? ((int)($_GET['member_id']??$memberId)) : $memberId;
    if (!$mid) jsonResponse(['success'=>false,'message'=>'Member required'], 400);
    $stmt = $pdo->prepare("SELECT rl.*, b.title, b.author, b.cover_url, b.status AS book_status, b.category FROM reading_list rl JOIN books b ON rl.book_id=b.id WHERE rl.member_id=? ORDER BY rl.added_at DESC");
    $stmt->execute([$mid]);
    jsonResponse(['success'=>true,'data'=>$stmt->fetchAll()]);
}

function addToReadingList(PDO $pdo, ?int $memberId): void {
    if (!$memberId) jsonResponse(['success'=>false,'message'=>'Members only'], 403);
    $b = getRequestBody();
    $bookId = (int)($b['book_id']??0);
    if (!$bookId) jsonResponse(['success'=>false,'message'=>'book_id required'], 400);
    try {
        $pdo->prepare("INSERT INTO reading_list (member_id,book_id) VALUES (?,?)")->execute([$memberId,$bookId]);
        jsonResponse(['success'=>true,'message'=>'Added to reading list']);
    } catch (PDOException $e) {
        jsonResponse(['success'=>false,'message'=>'Already in reading list'], 409);
    }
}

function removeFromReadingList(PDO $pdo, ?int $id, ?int $memberId): void {
    if (!$id || !$memberId) jsonResponse(['success'=>false,'message'=>'Missing params'], 400);
    $pdo->prepare("DELETE FROM reading_list WHERE id=? AND member_id=?")->execute([$id,$memberId]);
    jsonResponse(['success'=>true,'message'=>'Removed from reading list']);
}

// ── MEMBER STATS ───────────────────────────────────────────────────────────────
function getMemberStats(PDO $pdo, int $memberId): void {
    $a = $pdo->prepare("SELECT COUNT(*) FROM borrowings WHERE member_id=? AND status='active'"); $a->execute([$memberId]);
    $t = $pdo->prepare("SELECT COUNT(*) FROM borrowings WHERE member_id=?"); $t->execute([$memberId]);
    $o = $pdo->prepare("SELECT COUNT(*) FROM borrowings WHERE member_id=? AND status='active' AND due_date<CURDATE()"); $o->execute([$memberId]);
    $w = $pdo->prepare("SELECT COUNT(*) FROM reading_list WHERE member_id=?"); $w->execute([$memberId]);
    $r = $pdo->prepare("SELECT b.*, bk.title, bk.author, bk.cover_url FROM borrowings b JOIN books bk ON b.book_id=bk.id WHERE b.member_id=? ORDER BY b.created_at DESC LIMIT 5"); $r->execute([$memberId]);
    $recent = $r->fetchAll();
    foreach ($recent as &$row) {
        if ($row['status']==='active' && strtotime($row['due_date']) < strtotime('today')) $row['status']='overdue';
    } unset($row);
    jsonResponse(['success'=>true,'data'=>[
        'active_loans'   => (int)$a->fetchColumn(),
        'total_borrowed' => (int)$t->fetchColumn(),
        'overdue'        => (int)$o->fetchColumn(),
        'wishlist_count' => (int)$w->fetchColumn(),
        'recent_loans'   => $recent,
    ]]);
}

// ── OPEN LIBRARY ───────────────────────────────────────────────────────────────
function searchOpenLibrary(): void {
    $q     = $_GET['q'] ?? '';
    $limit = min((int)($_GET['limit']??10), 20);
    if (!$q) jsonResponse(['success'=>false,'message'=>'Query required'], 400);
    $url = "https://openlibrary.org/search.json?q=".urlencode($q)."&fields=key,title,author_name,cover_i,isbn,first_publish_year,subject&limit=".$limit;
    $ctx = stream_context_create(['http'=>['timeout'=>10,'user_agent'=>'Bibliotheca/2.0','ignore_errors'=>true]]);
    $res = @file_get_contents($url, false, $ctx);
    if ($res === false) jsonResponse(['success'=>false,'message'=>'Failed to reach Open Library'], 502);
    $data = json_decode($res, true);
    if (!$data) jsonResponse(['success'=>false,'message'=>'Invalid response from Open Library'], 502);
    $books = [];
    foreach (($data['docs']??[]) as $doc) {
        $cid = $doc['cover_i']??null;
        $books[] = [
            'title'            => $doc['title']??'Unknown Title',
            'author'           => implode(', ',$doc['author_name']??['Unknown Author']),
            'cover_url'        => $cid ? "https://covers.openlibrary.org/b/id/{$cid}-M.jpg" : null,
            'open_library_key' => $doc['key']??null,
            'isbn'             => $doc['isbn'][0]??null,
            'year_published'   => $doc['first_publish_year']??null,
            'category'         => $doc['subject'][0]??null,
        ];
    }
    jsonResponse(['success'=>true,'query'=>$q,'numFound'=>$data['numFound']??0,'data'=>$books]);
}

// ── ADMIN STATS ────────────────────────────────────────────────────────────────
function getDashboardStats(PDO $pdo): void {
    $stats = [
        'total_books'     => (int)$pdo->query("SELECT COUNT(*) FROM books")->fetchColumn(),
        'available_books' => (int)$pdo->query("SELECT COUNT(*) FROM books WHERE status='available'")->fetchColumn(),
        'borrowed_books'  => (int)$pdo->query("SELECT COUNT(*) FROM books WHERE status='borrowed'")->fetchColumn(),
        'total_members'   => (int)$pdo->query("SELECT COUNT(*) FROM members WHERE status='active'")->fetchColumn(),
        'active_loans'    => (int)$pdo->query("SELECT COUNT(*) FROM borrowings WHERE status='active'")->fetchColumn(),
        'overdue_loans'   => (int)$pdo->query("SELECT COUNT(*) FROM borrowings WHERE status='active' AND due_date<CURDATE()")->fetchColumn(),
    ];
    $stats['popular_books'] = $pdo->query("SELECT id,title,author,cover_url,times_borrowed,status FROM books ORDER BY times_borrowed DESC LIMIT 5")->fetchAll();
    $stats['recent_activity'] = $pdo->query("SELECT b.id,bk.title,m.name AS member_name,b.borrow_date,b.due_date,b.status FROM borrowings b JOIN books bk ON b.book_id=bk.id JOIN members m ON b.member_id=m.id ORDER BY b.created_at DESC LIMIT 5")->fetchAll();
    jsonResponse(['success'=>true,'data'=>$stats]);
}
