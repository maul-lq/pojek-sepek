<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>SkinDecide</title>
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Orbitron:wght@400;600;700;900&family=Syne:wght@400;500;600;700&family=JetBrains+Mono:wght@400;500&display=swap" rel="stylesheet">
    @vite(['resources/css/welcome.css', 'resources/js/welcome.js'])
</head>
<body style="{{ $customBackgroundStyle }}" data-custom-background-url="{{ $customBackgroundUrl }}" data-criterias='@json($criterias)' data-saved-inputs='@json($savedWelcomeInputs)'>
<!-- Header -->
    <header>
        <div class="logo">
            <div class="logo-dot"></div>
            <span class="logo-skin">SKIN</span><span class="logo-decide">DECIDE</span>
        </div>
        <div class="header-actions">
            <a href="/pengaturan" class="header-link">
                Pengaturan
            </a>
            <div class="header-badge">Promethee Team</div>
        </div>
    </header>

    <main>
        <div class="app-container">

            <div class="page-header">
                <div class="label">SkinDecide - Asisten Keputusan Pembelian Skin MLBB</div>
                <h1>Rekomendasi <span>Skin</span> <span class="glitch-text" data-text="Terbaik">Terbaik</span></h1>
                <p>Bingung mau beli skin yang mana? Masukkan pilihan skin yang sedang kamu bandingkan, beri penilaian kriteria, <br> dan biarkan sistem kami yang menghitung dan memberikan pilihan terbaik untukmu. <br> (Masukkan Skala 1-7, khusus Kategori masukkan skala 1-6, dan untuk Harga masukkan dalam jumlah Diamond)</p>
            </div>

            <form id="spkForm">
                <div id="container-alternatif"></div>

                <div class="actions-row">
                    <button type="button" class="btn-add" id="btn-add-skin">
                        <svg width="15" height="15" viewBox="0 0 15 15" fill="none" xmlns="http://www.w3.org/2000/svg">
                            <path d="M7.5 1v13M1 7.5h13" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/>
                        </svg>
                        Tambah Pilihan Skin
                    </button>
                    <button type="button" class="btn-clear-saved" id="btn-clear-saved">
                        Hapus Input Tersimpan
                    </button>
                    <button type="submit" class="btn-hitung" id="btn-hitung">
                        <div class="spinner" id="spinner"></div>
                        <svg id="calc-icon" width="16" height="16" viewBox="0 0 16 16" fill="none">
                            <rect x="1.5" y="1.5" width="13" height="13" rx="2" stroke="currentColor" stroke-width="1.4"/>
                            <path d="M4 5h8M4 8h4M4 11h2" stroke="currentColor" stroke-width="1.4" stroke-linecap="round"/>
                        </svg>
                        Hitung Rekomendasi
                    </button>
                </div>
            </form>

            <div id="section-hasil">
                <div class="hasil-header">
                    <h2>Hasil <span>Peringkat</span></h2>
                    <div class="hasil-divider"></div>
                </div>
                <div class="table-wrap">
                    <table>
                        <thead>
                            <tr>
                                <th class="table-rank-col">#</th>
                                <th>Nama Skin</th>
                                <th>Leaving Flow</th>
                                <th>Entering Flow</th>
                                <th class="th-score">Net Flow</th>
                            </tr>
                        </thead>
                        <tbody id="tabel-hasil"></tbody>
                    </table>
                </div>
            </div>

        </div>
    </main>
<!-- Footer -->
    <footer>
        <div class="footer-brand"><span>SKIN</span>DECIDE</div>
        <div class="footer-copy">&copy; 2026 Promethee Team</div>
    </footer>

    <input type="file" id="input-custom-bg" accept="image/*" style="display: none;">

</body>
</html>
