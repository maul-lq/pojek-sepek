import './bootstrap';
import { confirmAction } from './confirm-modal';

window.addEventListener('DOMContentLoaded', () => {

    const glitchPalettes = [
        { c1: '#00ffffcc', s1: '#00ffffcc',   c2: '#ff1457cc', s2: '#ff1457cc'   },
        { c1: '#ff1457cc', s1: '#ff1457cc',     c2: '#eeeeee', s2: '#fafafaee' },
        { c1: '#eeeeee', s1: '#fafafaee', c2: '#00ffffcc', s2: '#00ffffcc' },
        { c1: '#00ffffcc', s1: '#00ffffcc',   c2: '#ff1457cc', s2: '#ff1457cc'   },
        { c1: '#ff1457cc', s1: '#ff1457cc',     c2: '#eeeeee', s2: '#fafafaee' },
        { c1: '#eeeeee', s1: '#fafafaee', c2: '#00ffffcc', s2: '#00ffffcc' },
    ];

    function randomizeGlitchColors() {
        const palette = glitchPalettes[Math.floor(Math.random() * glitchPalettes.length)];
        const root = document.documentElement;
        root.style.setProperty('--glitch-color-1',  palette.c1);
        root.style.setProperty('--glitch-shadow-1', palette.s1);
        root.style.setProperty('--glitch-color-2',  palette.c2);
        root.style.setProperty('--glitch-shadow-2', palette.s2);
    }

    randomizeGlitchColors();
    setInterval(randomizeGlitchColors, 6000);

    const configuredBackground = document.body?.dataset.customBackgroundUrl;

    if (configuredBackground) {
        terapkanBackground(configuredBackground);
    }

    function terapkanBackground(urlGambar) {
        document.body.style.backgroundImage = `linear-gradient(to bottom, rgba(9, 13, 18, 0.85), rgba(9, 13, 18, 0.95)), url('${urlGambar}')`;
    }

    // ==========================================
    // 2. LOGIKA HALAMAN UTAMA & PERHITUNGAN SPK
    // ==========================================
    const form = document.getElementById('spkForm');
    const addButton = document.getElementById('btn-add-skin');
    const clearSavedButton = document.getElementById('btn-clear-saved');
    const container = document.getElementById('container-alternatif');
    const sectionHasil = document.getElementById('section-hasil');
    const detailButton = document.getElementById('btn-detail-calculation');
    const detailCalculation = document.getElementById('detail-calculation');

    // Jika element SPK di bawah ini tidak lengkap, abaikan sisa kode SPK tanpa merusak background
    if (!form || !addButton || !container || !sectionHasil) {
        return;
    }

    const daftarKriteria = getCriteriaDefinitions();
    const csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') ?? '';
    let urutanSkin = 0;
    let saveTimeout = null;
    let latestCalculation = null;

    addButton.addEventListener('click', () => {
        tambahBarisSkin();
        schedulePersistWelcomeInputs();
    });

    clearSavedButton?.addEventListener('click', clearSavedInputs);
    detailButton?.addEventListener('click', toggleCalculationDetails);
    detailCalculation?.addEventListener('click', handleCalculationTabClick);
    form.addEventListener('submit', prosesHitung);
    container.addEventListener('click', handleContainerClick);
    container.addEventListener('input', schedulePersistWelcomeInputs);
    container.addEventListener('change', schedulePersistWelcomeInputs);

    hydrateSavedInputs();

    function getCriteriaDefinitions() {
        return parseDataset('criterias', []).map((criteria) => ({
            id: criteria.id,
            name: criteria.name,
            isHarga: criteria.name.toLowerCase().includes('harga'),
            isRarity: criteria.name.toLowerCase().includes('rarity') || criteria.name.toLowerCase().includes('kategori'),
            isPreferensi: criteria.name.toLowerCase().includes('preferensi'),
            isKetersediaan: criteria.name.toLowerCase().includes('ketersediaan'),
        }));
    }

    function getSavedInputs() {
        return parseDataset('savedInputs', { alternatives: [] });
    }

    function parseDataset(key, defaultValue) {
        const rawData = document.body?.dataset[key];

        if (!rawData) {
            return defaultValue;
        }

        try {
            return JSON.parse(rawData);
        } catch (error) {
            console.error(error);

            return defaultValue;
        }
    }

    function hydrateSavedInputs() {
        const savedAlternatives = getSavedInputs().alternatives ?? [];

        if (savedAlternatives.length > 0) {
            savedAlternatives.forEach((alternative) => tambahBarisSkin(alternative));

            return;
        }

        tambahBarisSkin();
        tambahBarisSkin();
    }

    function escapeHtml(value) {
        return String(value)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;');
    }

    function renderOption(value, label, selectedValue) {
        const selected = String(selectedValue ?? '') === String(value) ? ' selected' : '';

        return `<option value="${value}"${selected}>${label}</option>`;
    }

    function valueAttribute(value) {
        if (value === null || value === undefined || value === '') {
            return '';
        }

        return ` value="${escapeHtml(value)}"`;
    }

    function urutkanUlangNomorSkin() {
        const semuaLabelNomor = document.querySelectorAll('.skin-card-number');
        semuaLabelNomor.forEach((label, index) => {
            label.textContent = `SKIN ${index + 1}`;
        });
    }

    function tambahBarisSkin(savedAlternative = null) {
        urutanSkin += 1;

        const scores = savedAlternative?.scores ?? {};
        const card = document.createElement('div');
        card.className = 'skin-card class-skin-item';
        card.id = `skin-row-${urutanSkin}`;
        card.style.animationDelay = '0ms';

        let criteriaHTML = '';
        daftarKriteria.forEach((kriteria) => {
            const savedScore = scores[kriteria.id] ?? scores[String(kriteria.id)] ?? '';

            if (kriteria.isHarga) {
                criteriaHTML += `
                        <div class="criteria-item">
                            <label>${kriteria.name}</label>
                            <input type="number" min="0" required name="kriteria_${kriteria.id}" placeholder="Misal: 1089"
                                class="criteria-input"${valueAttribute(savedScore)} step="any">
                            <p class="hint-text">Gacha: estimasi pity (Zodiac ~1500 · Collector ~4000 · Aspirants ~5000 · Legend ~9000)</p>
                        </div>`;
            } else if (kriteria.isRarity) {
                const selectedValue = savedScore || 1;
                criteriaHTML += `
                        <div class="criteria-item">
                            <label>${kriteria.name}</label>
                            <select name="kriteria_${kriteria.id}" class="criteria-select">
                                ${renderOption(1, 'Common (Basic / Elite / Season)', selectedValue)}
                                ${renderOption(2, 'Exceptional (Special / Starlight Regular)', selectedValue)}
                                ${renderOption(3, 'Deluxe (Epic Shop / Epic Squad Series / Zodiac)', selectedValue)}
                                ${renderOption(4, 'Exquisite (Epic Limited / Collector / Lucky Box / Starlight Annual)', selectedValue)}
                                ${renderOption(5, 'Grand (Collab Anime/Movie, Aspirants, Exorcists, Mistbenders)', selectedValue)}
                                ${renderOption(6, 'Legend (Legend Magic Wheel / Legend Limited Event)', selectedValue)}
                            </select>
                        </div>`;
            } else if (kriteria.isPreferensi) {
                const selectedValue = savedScore || 4;
                criteriaHTML += `
                        <div class="criteria-item">
                            <label>${kriteria.name}</label>
                            <select name="kriteria_${kriteria.id}" class="criteria-select">
                                ${renderOption(1, 'Tidak Pernah Dipakai', selectedValue)}
                                ${renderOption(2, 'Sangat Jarang Dipakai', selectedValue)}
                                ${renderOption(3, 'Jarang Dipakai', selectedValue)}
                                ${renderOption(4, 'Kadang-kadang', selectedValue)}
                                ${renderOption(5, 'Sering Dipakai', selectedValue)}
                                ${renderOption(6, 'Sangat Sering Dipakai', selectedValue)}
                                ${renderOption(7, 'Hero Andalan Utama (Signature)', selectedValue)}
                            </select>
                        </div>`;
            } else if (kriteria.isKetersediaan) {
                const selectedValue = savedScore || 1;
                criteriaHTML += `
                        <div class="criteria-item">
                            <label>${kriteria.name}</label>
                            <select name="kriteria_${kriteria.id}" class="criteria-select">
                                ${renderOption(1, 'Dapat Dibeli Kapan Saja di Shop', selectedValue)}
                                ${renderOption(2, 'Hanya Bisa Dibeli Saat Event Berlangsung (Limited)', selectedValue)}
                            </select>
                        </div>`;
            } else {
                const selectedValue = savedScore || 4;
                criteriaHTML += `
                        <div class="criteria-item">
                            <label>${kriteria.name}</label>
                            <select name="kriteria_${kriteria.id}" class="criteria-select">
                                ${renderOption(1, 'Sangat Kurang', selectedValue)}
                                ${renderOption(2, 'Kurang', selectedValue)}
                                ${renderOption(3, 'Agak Kurang', selectedValue)}
                                ${renderOption(4, 'Standar', selectedValue)}
                                ${renderOption(5, 'Lumayan Bagus', selectedValue)}
                                ${renderOption(6, 'Bagus', selectedValue)}
                                ${renderOption(7, 'Sangat Bagus', selectedValue)}
                            </select>
                        </div>`;
            }
        });

        card.innerHTML = `
                <div class="corner-deco corner-deco-tl"></div>
                <div class="corner-deco corner-deco-tr"></div>
                <div class="corner-deco corner-deco-bl"></div>
                <div class="corner-deco corner-deco-br"></div>
                <div class="skin-card-number">SKIN ${urutanSkin}</div>
                <button type="button" class="btn-hapus" data-action="remove-skin" data-skin-id="${urutanSkin}">✕ Hapus</button>
                <div class="skin-name-section">
                    <label>Nama / Varian Skin</label>
                    <input type="text" required name="nama_skin" placeholder="Misal: Gusion Cosmic Gleam"
                        class="input-name"${valueAttribute(savedAlternative?.name)}>
                </div>
                <div class="criteria-divider">
                    <div class="criteria-grid">${criteriaHTML}</div>
                </div>`;

        container.appendChild(card);

        // Menyelaraskan nomor urut sesaat setelah kartu baru masuk ke layar
        urutkanUlangNomorSkin();
    }

    function handleContainerClick(event) {
        const removeButton = event.target.closest('[data-action="remove-skin"]');
        if (!removeButton) return;
        hapusBarisSkin(removeButton.dataset.skinId);
    }

    function hapusBarisSkin(id) {
        const total = document.querySelectorAll('.class-skin-item').length;
        if (total <= 2) {
            shakeAlert('Minimal 2 skin untuk dibandingkan!');
            return;
        }

        const element = document.getElementById(`skin-row-${id}`);
        if (!element) return;

        element.classList.add('is-removing');
        window.setTimeout(() => {
            element.remove();
            
            // Urutkan ulang nomor skin agar teks SKIN X tetap berurutan (1, 2, 3...) setelah ada yang dihapus
            urutkanUlangNomorSkin();
            
            schedulePersistWelcomeInputs();
        }, 280);
    }

    function collectWelcomeInputs() {
        return Array.from(document.querySelectorAll('.class-skin-item')).map((row) => {
            const nama = row.querySelector('input[name="nama_skin"]')?.value ?? '';
            const scores = {};

            daftarKriteria.forEach((kriteria) => {
                const input = row.querySelector(`[name="kriteria_${kriteria.id}"]`);
                scores[kriteria.id] = input?.value ?? '';
            });

            return { name: nama, scores };
        });
    }

    function schedulePersistWelcomeInputs() {
        window.clearTimeout(saveTimeout);
        saveTimeout = window.setTimeout(() => {
            persistWelcomeInputs();
        }, 350);
    }

    async function persistWelcomeInputs(showError = false) {
        try {
            await fetch('/skin-inputs', {
                method: 'POST',
                credentials: 'same-origin',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': csrfToken,
                    'Accept': 'application/json',
                },
                body: JSON.stringify({ alternatives: collectWelcomeInputs() }),
            });
        } catch (error) {
            console.error(error);

            if (showError) {
                shakeAlert('Input belum bisa disimpan ke sesi.');
            }
        }
    }

    async function clearSavedInputs() {
        const confirmed = await confirmAction({
            title: 'Hapus Input Tersimpan?',
            message: 'Semua input skin yang tersimpan di sesi browser ini akan dihapus dan form akan dibuat ulang dari awal.',
            confirmText: 'Ya, hapus input',
            cancelText: 'Batal',
        });

        if (!confirmed) {
            return;
        }

        try {
            await fetch('/skin-inputs', {
                method: 'DELETE',
                credentials: 'same-origin',
                headers: {
                    'X-CSRF-TOKEN': csrfToken,
                    'Accept': 'application/json',
                },
            });

            container.innerHTML = '';
            sectionHasil.classList.remove('visible');
            resetCalculationDetails();
            urutanSkin = 0;
            tambahBarisSkin();
            tambahBarisSkin();
            shakeAlert('Input tersimpan sudah dihapus.');
        } catch (error) {
            console.error(error);
            shakeAlert('Gagal menghapus input tersimpan.');
        }
    }

    function shakeAlert(message) {
        const existing = document.getElementById('shake-toast');
        if (existing) existing.remove();

        const toast = document.createElement('div');
        toast.id = 'shake-toast';
        toast.className = 'shake-toast';
        toast.textContent = message;
        document.body.appendChild(toast);

        window.setTimeout(() => toast.remove(), 2500);
    }

    async function prosesHitung(event) {
        event.preventDefault();

        const btn = document.getElementById('btn-hitung');
        const spinner = document.getElementById('spinner');
        const calcIcon = document.getElementById('calc-icon');

        if (!btn || !spinner || !calcIcon) return;

        btn.classList.add('loading');
        spinner.style.display = 'block';
        calcIcon.style.display = 'none';

        const rows = document.querySelectorAll('.class-skin-item');
        const payloadAlternatives = [];

        rows.forEach((row) => {
            const nama = row.querySelector('input[name="nama_skin"]')?.value ?? '';
            const scores = {};

            daftarKriteria.forEach((kriteria) => {
                const input = row.querySelector(`[name="kriteria_${kriteria.id}"]`);
                scores[kriteria.id] = parseFloat(input?.value ?? '0');
            });

            payloadAlternatives.push({ name: nama, scores });
        });

        try {
            const recommendationRequest = fetch('/api/hitung-rekomendasi', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'Accept': 'application/json',
                },
                body: JSON.stringify({ alternatives: payloadAlternatives }),
            });

            const [response] = await Promise.all([
                recommendationRequest,
                persistWelcomeInputs(true),
            ]);
            const hasil = await parseJsonResponse(response);

            if (!response.ok) {
                shakeAlert(hasil.message || 'Gagal memproses rekomendasi.');

                return;
            }

            if (hasil.status === 'success') {
                tampilkanTabelHasil(hasil.rekomendasi);
                latestCalculation = hasil.perhitungan ?? null;
                renderCalculationDetails();
            } else {
                shakeAlert(hasil.message || 'Terjadi kesalahan sistem.');
            }
        } catch (error) {
            console.error(error);
            shakeAlert('Gagal memproses rekomendasi. Periksa koneksi atau server API.');
        } finally {
            btn.classList.remove('loading');
            spinner.style.display = 'none';
            calcIcon.style.display = 'block';
        }
    }

    async function parseJsonResponse(response) {
        try {
            return await response.json();
        } catch (error) {
            console.error(error);

            return {};
        }
    }

    function tampilkanTabelHasil(data) {
        const tbody = document.getElementById('tabel-hasil');
        if (!tbody) return;

        tbody.innerHTML = '';
        if (data.length === 0) return;

        const maxNetFlow = data[0].net_flow;
        const isTie = data.filter((item) => Math.abs(item.net_flow - maxNetFlow) < 0.0001).length > 1;

        data.forEach((item, index) => {
            const isTop = Math.abs(item.net_flow - maxNetFlow) < 0.0001;
            const rank = isTop ? 1 : index + 1;
            const scoreClass = item.net_flow >= 0 ? 'score-positive' : 'score-negative';
            const formattedScore = `${item.net_flow >= 0 ? '+' : ''}${item.net_flow.toFixed(4)}`;

            let badgeHtml = '';
            if (isTop) {
                badgeHtml = isTie
                    ? '<span class="tie-badge">🤝 SERI (REKOMENDASI)</span>'
                    : '<span class="trophy-badge">🏆 REKOMENDASI</span>';
            }

            const row = document.createElement('tr');
            row.className = isTop ? 'rank-1' : '';
            row.innerHTML = `
                    <td class="${isTop ? 'td-rank-1' : 'td-rank'}">${rank}</td>
                    <td class="${isTop ? 'td-name-1' : 'td-name'}">
                        ${escapeHtml(item.name)}
                        ${badgeHtml}
                    </td>
                    <td class="td-flow">${item.leaving_flow.toFixed(4)}</td>
                    <td class="td-flow">${item.entering_flow.toFixed(4)}</td>
                    <td class="td-score ${scoreClass}">${formattedScore}</td>
                `;
            tbody.appendChild(row);
        });

        sectionHasil.classList.add('visible');
        sectionHasil.scrollIntoView({ behavior: 'smooth', block: 'start' });
    }

    function toggleCalculationDetails() {
        if (!detailButton || !detailCalculation || !latestCalculation) {
            return;
        }

        const isExpanded = detailButton.getAttribute('aria-expanded') === 'true';
        detailButton.setAttribute('aria-expanded', String(!isExpanded));
        detailCalculation.hidden = isExpanded;

        if (!isExpanded) {
            detailCalculation.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
        }
    }

    function resetCalculationDetails() {
        latestCalculation = null;

        if (detailButton) {
            detailButton.setAttribute('aria-expanded', 'false');
        }

        if (detailCalculation) {
            detailCalculation.hidden = true;
            detailCalculation.innerHTML = '';
        }
    }

    function renderCalculationDetails() {
        if (!detailButton || !detailCalculation || !latestCalculation) {
            return;
        }

        const alternatives = latestCalculation.alternatives ?? [];
        const criteria = latestCalculation.criteria ?? [];

        detailCalculation.innerHTML = `
            <div class="detail-calculation-header">
                <div>
                    <span class="detail-calculation-kicker">Audit PROMETHEE</span>
                    <h3>Rincian Perhitungan Alternatif</h3>
                </div>
                <p>Pilih tahap perhitungan untuk melihat tabel tanpa memanjangkan halaman.</p>
            </div>
            <div class="calculation-tabs" role="tablist" aria-label="Tahap perhitungan PROMETHEE">
                ${renderCalculationTabButton('deviation', 'Tabel Deviasi', true)}
                ${renderCalculationTabButton('preference', 'Preferensi Kriteria')}
                ${renderCalculationTabButton('index', 'Indeks Preferensi')}
                ${renderCalculationTabButton('flow', 'Flow')}
            </div>
            ${renderCalculationTabPanel('deviation', renderPairwiseCriteriaTable('Tabel Deviasi', 'deviations', criteria), true)}
            ${renderCalculationTabPanel('preference', renderPairwiseCriteriaTable('Preferensi Kriteria', 'criterion_preferences', criteria))}
            ${renderCalculationTabPanel('index', renderPreferenceIndexTable(alternatives))}
            ${renderCalculationTabPanel('flow', renderFlowTable())}
        `;

        detailCalculation.hidden = true;
        detailButton.setAttribute('aria-expanded', 'false');
    }

    function renderCalculationTabButton(tabName, label, isActive = false) {
        return `
            <button type="button" class="calculation-tab${isActive ? ' is-active' : ''}" role="tab"
                id="calculation-tab-${tabName}" data-calculation-tab="${tabName}"
                aria-controls="calculation-panel-${tabName}" aria-selected="${isActive}">
                ${label}
            </button>
        `;
    }

    function renderCalculationTabPanel(tabName, content, isActive = false) {
        return `
            <section class="calculation-tab-panel${isActive ? ' is-active' : ''}" role="tabpanel"
                id="calculation-panel-${tabName}" aria-labelledby="calculation-tab-${tabName}"${isActive ? '' : ' hidden'}>
                ${content}
            </section>
        `;
    }

    function handleCalculationTabClick(event) {
        const selectedTab = event.target.closest('[data-calculation-tab]');

        if (!selectedTab || !detailCalculation) {
            return;
        }

        detailCalculation.querySelectorAll('[data-calculation-tab]').forEach((tab) => {
            const isSelected = tab === selectedTab;
            tab.classList.toggle('is-active', isSelected);
            tab.setAttribute('aria-selected', String(isSelected));
        });

        detailCalculation.querySelectorAll('.calculation-tab-panel').forEach((panel) => {
            const isSelected = panel.id === `calculation-panel-${selectedTab.dataset.calculationTab}`;
            panel.classList.toggle('is-active', isSelected);
            panel.hidden = !isSelected;
        });
    }

    function renderPairwiseCriteriaTable(title, valueKey, criteria) {
        const comparisons = latestCalculation.pairwise_comparisons ?? [];
        const criteriaHeadings = criteria.map((criterion, index) => `
            <th title="${escapeHtml(criterion.name)}">C${index + 1}</th>
        `).join('');
        const rows = comparisons.map((comparison) => {
            const values = criteria.map((criterion) => `
                <td>${formatCalculationNumber(comparison[valueKey]?.[criterion.id])}</td>
            `).join('');

            return `
                <tr>
                    <th>${escapeHtml(comparison.alternative_a)}</th>
                    <th>${escapeHtml(comparison.alternative_b)}</th>
                    ${values}
                </tr>
            `;
        }).join('');

        return `
            <section class="calculation-card">
                <div class="calculation-card-heading">
                    <div>
                        <span class="calculation-step">Perbandingan Berpasangan</span>
                        <h4>${title}</h4>
                    </div>
                </div>
                <div class="criteria-key">
                    ${criteria.map((criterion, index) => `<span><strong>C${index + 1}</strong>${escapeHtml(criterion.name)}</span>`).join('')}
                </div>
                <div class="matrix-scroll">
                    <table class="calculation-table pairwise-table">
                        <thead><tr><th>A1</th><th>A2</th>${criteriaHeadings}</tr></thead>
                        <tbody>${rows}</tbody>
                    </table>
                </div>
            </section>
        `;
    }

    function renderPreferenceIndexTable(alternatives) {
        const preferenceIndices = latestCalculation.preference_indices ?? [];
        const flows = latestCalculation.flows ?? [];
        const headings = alternatives.map((alternative) => `<th>${escapeHtml(alternative.name)}</th>`).join('');
        const bodyRows = alternatives.map((alternative, rowIndex) => {
            const cells = alternatives.map((_, columnIndex) => `
                <td>${formatCalculationNumber(preferenceIndices?.[rowIndex]?.[columnIndex])}</td>
            `).join('');

            return `
                <tr>
                    <th>${escapeHtml(alternative.name)}</th>
                    ${cells}
                    <td class="flow-highlight">${formatCalculationNumber(flows?.[rowIndex]?.leaving_flow)}</td>
                </tr>
            `;
        }).join('');
        const enteringCells = alternatives.map((_, index) => `
            <td class="flow-highlight">${formatCalculationNumber(flows?.[index]?.entering_flow)}</td>
        `).join('');

        return `
            <section class="calculation-card">
                <div class="calculation-card-heading">
                    <div>
                        <span class="calculation-step">Agregasi</span>
                        <h4>Indeks Preferensi</h4>
                    </div>
                </div>
                <div class="matrix-block">
                    <div class="matrix-scroll">
                        <table class="calculation-table preference-index-table">
                            <thead>
                                <tr>
                                    <th>Alternatif</th>
                                    ${headings}
                                    <th>Leaving</th>
                                </tr>
                            </thead>
                            <tbody>${bodyRows}</tbody>
                            <tfoot>
                                <tr>
                                    <th>Entering Flow</th>
                                    ${enteringCells}
                                    <td>—</td>
                                </tr>
                            </tfoot>
                        </table>
                    </div>
                </div>
            </section>
        `;
    }

    function renderFlowTable() {
        const flows = latestCalculation.flows ?? [];
        const rows = flows.map((flow) => `
            <tr>
                <th>${escapeHtml(flow.name)}</th>
                <td>${formatCalculationNumber(flow.leaving_flow)}</td>
                <td>${formatCalculationNumber(flow.entering_flow)}</td>
                <td class="${flowValueClass(flow.net_flow)}">${formatSignedNumber(flow.net_flow)}</td>
            </tr>
        `).join('');

        return `
            <section class="calculation-card">
                <div class="calculation-card-heading">
                    <div>
                        <span class="calculation-step">Hasil Flow</span>
                        <h4>Leaving, Entering, dan Net Flow</h4>
                    </div>
                </div>
                <div class="matrix-scroll">
                    <table class="calculation-table flow-summary-table">
                        <thead><tr><th>Alternatif</th><th>Leaving</th><th>Entering</th><th>Net</th></tr></thead>
                        <tbody>${rows}</tbody>
                    </table>
                </div>
            </section>
        `;
    }

    function formatCalculationNumber(value) {
        if (value === null || value === undefined || Number.isNaN(Number(value))) {
            return '—';
        }

        return Number(value).toFixed(4);
    }

    function formatSignedNumber(value) {
        if (value === null || value === undefined || Number.isNaN(Number(value))) {
            return '—';
        }

        const numericValue = Number(value);

        return `${numericValue >= 0 ? '+' : ''}${numericValue.toFixed(4)}`;
    }

    function flowValueClass(value) {
        return Number(value) >= 0 ? 'flow-positive' : 'flow-negative';
    }
});
