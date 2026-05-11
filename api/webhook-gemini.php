<?php
// =============================================
//  PRDForge AI - WhatsApp Chatbot
//  Stack: Fonnte + Google Gemini + PHP + Supabase
// =============================================

$GEMINI_KEY = "AIzaSyAos5U2e_A-vXNcXmxHeNOUMIjrlrET4CA";
$FONNTE_KEY = "bVvXkns3GwzdCmr8qe1M";

// Supabase Config
$SUPABASE_URL = "https://nwmfeschfxzruasdfzrb.supabase.co";
$SUPABASE_KEY = "sb_publishable_6qBp0vetrjnRuxqr5msd7w__fBPEy3U";

// ── Helper: Baca & Tulis State ke Supabase ────
function getState(string $phone): array {
    global $SUPABASE_URL, $SUPABASE_KEY;
    
    $ch = curl_init("$SUPABASE_URL/rest/v1/chat_states?phone_number=eq.$phone");
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => [
            "Authorization: Bearer $SUPABASE_KEY",
            "apikey: $SUPABASE_KEY"
        ],
    ]);
    $res = curl_exec($ch);
    curl_close($ch);

    $data = json_decode($res, true);
    if (!empty($data)) {
        return [
            "state" => $data[0]["state"] ?? "idle",
            "data" => json_decode($data[0]["data"] ?? "{}", true)
        ];
    }
    return ["state" => "idle", "data" => []];
}

function setState(string $phone, string $state, array $data = []): void {
    global $SUPABASE_URL, $SUPABASE_KEY;
    
    $payload = [
        "phone_number" => $phone,
        "state" => $state,
        "data" => json_encode($data),
        "updated_at" => date("c")
    ];

    // Cek apakah sudah ada
    $ch = curl_init("$SUPABASE_URL/rest/v1/chat_states?phone_number=eq.$phone");
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => [
            "Authorization: Bearer $SUPABASE_KEY",
            "apikey: $SUPABASE_KEY"
        ],
    ]);
    $res = curl_exec($ch);
    curl_close($ch);
    $existing = json_decode($res, true);

    if (!empty($existing)) {
        // UPDATE
        $ch = curl_init("$SUPABASE_URL/rest/v1/chat_states?phone_number=eq.$phone");
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST => "PATCH",
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => [
                "Content-Type: application/json",
                "Authorization: Bearer $SUPABASE_KEY",
                "apikey: $SUPABASE_KEY"
            ],
            CURLOPT_POSTFIELDS => json_encode($payload),
        ]);
    } else {
        // INSERT
        $ch = curl_init("$SUPABASE_URL/rest/v1/chat_states");
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => [
                "Content-Type: application/json",
                "Authorization: Bearer $SUPABASE_KEY",
                "apikey: $SUPABASE_KEY"
            ],
            CURLOPT_POSTFIELDS => json_encode($payload),
        ]);
    }
    curl_exec($ch);
    curl_close($ch);
}

// ── Helper: Kirim Pesan via Fonnte ───────────
function sendWA(string $FONNTE_KEY, string $to, string $message): void {
    $ch = curl_init("https://api.fonnte.com/send");
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => ["Authorization: $FONNTE_KEY"],
        CURLOPT_POSTFIELDS     => ["target" => $to, "message" => $message],
    ]);
    curl_exec($ch);
    curl_close($ch);
}

// ── Helper: Tanya Gemini AI ──────────────────
function askGemini(string $GEMINI_KEY, string $text): string {
    $systemPrompt = <<<PROMPT
Kamu adalah asisten WhatsApp PRDForge AI yang profesional dan ramah.
PRDForge AI adalah platform untuk membuat Product Requirement Document (PRD) dan arsitektur sistem secara otomatis menggunakan AI.

Paket yang tersedia:
- Free: 10 Token gratis, model dasar
- Pro: Rp 30.000/bulan, 60 Token/bulan, semua model AI
- Lifetime: Rp 199.000 sekali bayar, Token unlimited selamanya

Aturan jawaban:
- Gunakan teks polos tanpa markdown atau simbol bintang
- Singkat dan langsung ke inti (maks 3 paragraf)
- Gunakan tanda "-" jika perlu poin
- Akhiri dengan emoticon 🙂
PROMPT;

    $payload = [
        "contents" => [[
            "role"  => "user",
            "parts" => [["text" => $systemPrompt . "\n\nPertanyaan user:\n" . $text]]
        ]]
    ];

    $url = "https://generativelanguage.googleapis.com/v1beta/models/gemini-2.5-flash:generateContent?key=$GEMINI_KEY";
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => ["Content-Type: application/json"],
        CURLOPT_POSTFIELDS     => json_encode($payload),
    ]);
    $res = curl_exec($ch);
    curl_close($ch);

    $r = json_decode($res, true);
    return $r["candidates"][0]["content"]["parts"][0]["text"]
        ?? "Mohon maaf, saya tidak dapat menjawab saat ini.";
}

// ══════════════════════════════════════════════
//  MAIN: Terima pesan dari Fonnte
// ══════════════════════════════════════════════
$raw  = file_get_contents("php://input");
$data = json_decode($raw, true);
if (!$data) exit;

$from = $data["sender"] ?? "";
$text = trim($data["message"] ?? "");
if ($from === "" || $text === "") exit;

$textLower = strtolower($text);
$userState = getState($from);
$state     = $userState["state"];

// ── MENU UTAMA ────────────────────────────────
$menuUtama = 
"Halo! Selamat datang di PRDForge AI 🤖\n\n" .
"Saya siap membantu Anda. Pilih menu:\n\n" .
"1 - Upgrade ke Pro Plan (Rp 30.000/bln)\n" .
"2 - Upgrade ke Lifetime Pro (Rp 199.000)\n" .
"3 - Info & Fitur PRDForge AI\n" .
"4 - Bantuan / Tanya AI\n\n" .
"Ketik angka pilihanmu 👆";

// ── KEYWORD TRIGGER (dari tombol di website) ──
$isUpgradeProTrigger      = str_contains($textLower, "upgrade") && str_contains($textLower, "pro");
$isUpgradeLifetimeTrigger = str_contains($textLower, "upgrade") && str_contains($textLower, "lifetime");
$isGreeting               = in_array($textLower, ["halo", "hi", "hello", "hai", "mulai", "start", "menu"]);

// ══════════════════════════════════════════════
//  ROUTING BERDASARKAN STATE
// ══════════════════════════════════════════════

// --- Trigger dari website: klik Pro Plan ---
if ($isUpgradeProTrigger && $state === "idle") {
    setState($from, "confirm_pro");
    sendWA($FONNTE_KEY, $from,
        "Halo! Saya lihat Anda tertarik dengan Paket Pro 🚀\n\n" .
        "Paket Pro - Rp 30.000/bulan:\n" .
        "- 60 Token AI per bulan\n" .
        "- Akses semua model AI\n" .
        "- Priority support\n\n" .
        "Pilih metode pembayaran:\n\n" .
        "1 - Transfer Bank BCA\n" .
        "2 - QRIS\n" .
        "3 - GoPay / OVO / Dana\n" .
        "0 - Batal"
    );
    exit;
}

// --- Trigger dari website: klik Lifetime ---
if ($isUpgradeLifetimeTrigger && $state === "idle") {
    setState($from, "confirm_lifetime");
    sendWA($FONNTE_KEY, $from,
        "Halo! Anda memilih Paket Lifetime Pro ⭐\n\n" .
        "Lifetime Pro - Rp 199.000 (sekali bayar):\n" .
        "- Token AI UNLIMITED selamanya\n" .
        "- Akses semua model AI\n" .
        "- Semua update fitur gratis\n\n" .
        "Pilih metode pembayaran:\n\n" .
        "1 - Transfer Bank BCA\n" .
        "2 - QRIS\n" .
        "3 - GoPay / OVO / Dana\n" .
        "0 - Batal"
    );
    exit;
}

// --- Greeting / Menu Utama ---
if ($isGreeting || $text === "0") {
    setState($from, "idle");
    sendWA($FONNTE_KEY, $from, $menuUtama);
    exit;
}

// ──────────────────────────────────────────────
//  STATE MACHINE: Pilihan Pembayaran Pro
// ──────────────────────────────────────────────
if ($state === "confirm_pro") {
    if ($text === "1") {
        setState($from, "waiting_proof", ["plan" => "pro", "method" => "BCA"]);
        sendWA($FONNTE_KEY, $from,
            "Silakan transfer ke rekening berikut:\n\n" .
            "Bank  : BCA\n" .
            "No. Rek : 1234567890\n" .
            "Atas Nama : PRDForge AI\n" .
            "Nominal : Rp 30.000\n\n" .
            "Setelah transfer, kirim foto bukti pembayaran ke sini ya! 📸\n" .
            "Tim kami akan aktivasi akun Anda dalam 1x24 jam 🙂"
        );
    } elseif ($text === "2") {
        setState($from, "waiting_proof", ["plan" => "pro", "method" => "QRIS"]);
        sendWA($FONNTE_KEY, $from,
            "Silakan scan QRIS berikut untuk pembayaran Rp 30.000:\n\n" .
            "[Gambar QRIS akan dikirim admin]\n\n" .
            "Setelah bayar, kirim foto bukti pembayaran ke sini 📸"
        );
    } elseif ($text === "3") {
        setState($from, "waiting_proof", ["plan" => "pro", "method" => "E-Wallet"]);
        sendWA($FONNTE_KEY, $from,
            "Transfer ke salah satu e-wallet berikut:\n\n" .
            "GoPay / OVO / Dana\n" .
            "No. : 0895370984358\n" .
            "Atas Nama : PRDForge AI\n" .
            "Nominal : Rp 30.000\n\n" .
            "Setelah transfer, kirim foto bukti pembayaran ke sini 📸"
        );
    } elseif ($text === "0") {
        setState($from, "idle");
        sendWA($FONNTE_KEY, $from, "Dibatalkan. Ketik \"menu\" kapan saja jika ingin kembali 🙂");
    } else {
        sendWA($FONNTE_KEY, $from, "Pilihan tidak valid. Ketik 1, 2, 3, atau 0 untuk batal.");
    }
    exit;
}

// ──────────────────────────────────────────────
//  STATE MACHINE: Pilihan Pembayaran Lifetime
// ──────────────────────────────────────────────
if ($state === "confirm_lifetime") {
    if ($text === "1") {
        setState($from, "waiting_proof", ["plan" => "lifetime", "method" => "BCA"]);
        sendWA($FONNTE_KEY, $from,
            "Silakan transfer ke rekening berikut:\n\n" .
            "Bank  : BCA\n" .
            "No. Rek : 1234567890\n" .
            "Atas Nama : PRDForge AI\n" .
            "Nominal : Rp 199.000\n\n" .
            "Setelah transfer, kirim foto bukti pembayaran ke sini! 📸\n" .
            "Akun Lifetime Anda akan diaktifkan dalam 1x24 jam 🙂"
        );
    } elseif ($text === "2") {
        setState($from, "waiting_proof", ["plan" => "lifetime", "method" => "QRIS"]);
        sendWA($FONNTE_KEY, $from,
            "Silakan scan QRIS berikut untuk pembayaran Rp 199.000:\n\n" .
            "[Gambar QRIS akan dikirim admin]\n\n" .
            "Setelah bayar, kirim foto bukti pembayaran ke sini 📸"
        );
    } elseif ($text === "3") {
        setState($from, "waiting_proof", ["plan" => "lifetime", "method" => "E-Wallet"]);
        sendWA($FONNTE_KEY, $from,
            "Transfer ke salah satu e-wallet berikut:\n\n" .
            "GoPay / OVO / Dana\n" .
            "No. : 0895370984358\n" .
            "Atas Nama : PRDForge AI\n" .
            "Nominal : Rp 199.000\n\n" .
            "Setelah transfer, kirim foto bukti pembayaran ke sini 📸"
        );
    } elseif ($text === "0") {
        setState($from, "idle");
        sendWA($FONNTE_KEY, $from, "Dibatalkan. Ketik \"menu\" kapan saja jika ingin kembali 🙂");
    } else {
        sendWA($FONNTE_KEY, $from, "Pilihan tidak valid. Ketik 1, 2, 3, atau 0 untuk batal.");
    }
    exit;
}

// ──────────────────────────────────────────────
//  STATE: Menunggu Bukti Pembayaran
// ──────────────────────────────────────────────
if ($state === "waiting_proof") {
    global $SUPABASE_URL, $SUPABASE_KEY;
    
    $plan   = $userState["data"]["plan"] ?? "pro";
    $method = $userState["data"]["method"] ?? "-";
    $msgType = $data["type"] ?? "text";
    $imageUrl = $data["image_url"] ?? null;

    if ($msgType === "image" || str_contains($textLower, "bukti") || str_contains($textLower, "transfer") || str_contains($textLower, "sudah")) {
        $amount = ($plan === "lifetime") ? 199000 : 30000;

        $paymentData = [
            "phone_number" => $from,
            "plan" => $plan,
            "payment_method" => $method,
            "amount" => $amount,
            "proof_image_url" => $imageUrl,
            "status" => "pending"
        ];

        $ch = curl_init("$SUPABASE_URL/rest/v1/payments");
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => [
                "Content-Type: application/json",
                "Authorization: Bearer $SUPABASE_KEY",
                "apikey: $SUPABASE_KEY"
            ],
            CURLOPT_POSTFIELDS => json_encode($paymentData),
        ]);
        curl_exec($ch);
        curl_close($ch);

        setState($from, "idle");
        sendWA($FONNTE_KEY, $from,
            "Terima kasih! Bukti pembayaran Anda sudah kami terima 🙏\n\n" .
            "Detail pesanan:\n" .
            "- Paket : " . strtoupper($plan) . "\n" .
            "- Metode : $method\n" .
            "- Nominal : Rp " . number_format($amount, 0, ",", ".") . "\n\n" .
            "Akun Anda akan diaktifkan maksimal 1x24 jam.\n" .
            "Jika ada pertanyaan, ketik \"menu\" untuk kembali ke menu utama 🙂"
        );

        sendWA($FONNTE_KEY, "62895370984358", "Ada pembayaran baru!\nDari: $from\nPaket: $plan\nMetode: $method\nNominal: Rp " . number_format($amount, 0, ",", "."));
    } else {
        sendWA($FONNTE_KEY, $from,
            "Silakan kirim foto bukti pembayaran Anda 📸\n\n" .
            "Jika ingin batal, ketik \"0\"."
        );
    }
    exit;
}

// ──────────────────────────────────────────────
//  Menu Pilihan dari Pesan Idle
// ──────────────────────────────────────────────
if ($state === "idle") {
    if ($text === "1") {
        setState($from, "confirm_pro");
        sendWA($FONNTE_KEY, $from,
            "Paket Pro - Rp 30.000/bulan 🚀\n\n" .
            "Pilih metode pembayaran:\n\n" .
            "1 - Transfer Bank BCA\n" .
            "2 - QRIS\n" .
            "3 - GoPay / OVO / Dana\n" .
            "0 - Batal"
        );
        exit;
    } elseif ($text === "2") {
        setState($from, "confirm_lifetime");
        sendWA($FONNTE_KEY, $from,
            "Paket Lifetime Pro - Rp 199.000 ⭐\n\n" .
            "Pilih metode pembayaran:\n\n" .
            "1 - Transfer Bank BCA\n" .
            "2 - QRIS\n" .
            "3 - GoPay / OVO / Dana\n" .
            "0 - Batal"
        );
        exit;
    } elseif ($text === "3") {
        sendWA($FONNTE_KEY, $from,
            "PRDForge AI adalah platform AI untuk membuat:\n\n" .
            "- Product Requirement Document (PRD)\n" .
            "- Arsitektur sistem software\n" .
            "- Analisis konsep produk\n\n" .
            "Cukup masukkan ide Anda, AI akan menghasilkan dokumen lengkap dalam hitungan detik!\n\n" .
            "Coba sekarang di: https://prdforge.ai 🙂"
        );
        exit;
    } elseif ($text === "4") {
        setState($from, "ask_ai");
        sendWA($FONNTE_KEY, $from, "Silakan ketik pertanyaan Anda, saya akan menjawab dengan AI 🤖");
        exit;
    }
}

// ──────────────────────────────────────────────
//  STATE: Tanya Jawab Bebas dengan AI
// ──────────────────────────────────────────────
if ($state === "ask_ai") {
    if ($text === "0" || $text === "menu") {
        setState($from, "idle");
        sendWA($FONNTE_KEY, $from, $menuUtama);
    } else {
        $reply = askGemini($GEMINI_KEY, $text);
        sendWA($FONNTE_KEY, $from, $reply . "\n\nKetik \"menu\" untuk kembali ke menu utama.");
    }
    exit;
}

// ──────────────────────────────────────────────
//  FALLBACK: Pesan tidak dikenali → tampilkan menu
// ──────────────────────────────────────────────
setState($from, "idle");
sendWA($FONNTE_KEY, $from, $menuUtama);
