<?php
/**
 * OptiLifeSync - Ortak "Öğün Ekle" penceresi
 * ───────────────────────────────────────────
 * Özet ve Beslenme sayfalarında kullanılır. Bootstrap JS'ten SONRA dahil edilmelidir.
 *
 * Sekmeler: Yazarak (yapay zeka) · Fotoğraf (yapay zeka) · Sık yenenler · Elle · Takviye
 * Kaydedince document üzerinde 'opti:food-added' olayı yayınlanır.
 * JS: OptiQuickAdd.open({ tab: 'text', date: 'YYYY-MM-DD' })
 */
$qaHour = (int) date('H');
$qaDefaultMeal = $qaHour < 11 ? 'breakfast' : ($qaHour < 16 ? 'lunch' : ($qaHour < 21 ? 'dinner' : 'snack'));
?>
<style>
    .qa-tabs { display: flex; overflow-x: auto; scrollbar-width: none; }
    .qa-tabs::-webkit-scrollbar { display: none; }
    .qa-tabs button { flex: 1 0 auto; white-space: nowrap; padding: 7px 12px; }
    .qa-macro-grid { display: grid; grid-template-columns: repeat(4, 1fr); gap: 8px; }
    .qa-cell { background: var(--surface-2); border: 1px solid var(--border); border-radius: 12px; padding: 8px 6px 6px; text-align: center; }
    .qa-cell .l { font-size: 11px; color: var(--muted); font-weight: 600; margin-bottom: 4px; }
    .qa-cell input.form-control { text-align: center; font-weight: 800; color: var(--mc) !important; padding: 4px !important; font-size: 16px !important; }
    .qa-drop { border: 2px dashed var(--border-strong); border-radius: 16px; padding: 24px 16px; text-align: center; background: var(--surface-2); }
    .qa-item { display: flex; align-items: center; gap: 12px; padding: 10px 12px; border: 1px solid var(--border); border-radius: 12px; cursor: pointer; margin-bottom: 8px; transition: border-color .15s, background .15s; }
    .qa-item.selected, .qa-item:hover { border-color: var(--accent-bright); background: var(--accent-dim); }
    .qa-item .kc { font-weight: 800; white-space: nowrap; font-variant-numeric: tabular-nums; }
    @media (max-width: 420px) { .qa-macro-grid { grid-template-columns: repeat(2, 1fr); } }
</style>

<div class="modal fade" id="quickAddModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered modal-lg">
    <div class="modal-content">
        <div class="modal-header">
            <div>
                <h5 class="modal-title fw-bold">Öğün ekle</h5>
                <div class="small" style="color:var(--muted)" id="qaDateLabel"></div>
            </div>
            <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
            <div class="seg qa-tabs w-100 mb-3" role="tablist">
                <button type="button" class="active" data-qa="text"><i class="bi bi-stars me-1"></i>Yazarak</button>
                <button type="button" data-qa="photo"><i class="bi bi-camera me-1"></i>Fotoğraf</button>
                <button type="button" data-qa="frequent"><i class="bi bi-clock-history me-1"></i>Sık yenenler</button>
                <button type="button" data-qa="manual"><i class="bi bi-pencil me-1"></i>Elle</button>
                <button type="button" data-qa="supp"><i class="bi bi-capsule me-1"></i>Takviye</button>
            </div>

            <div class="mb-3" id="qaMealWrap">
                <label class="form-label">Öğün</label>
                <select id="mealTypeSel" class="form-select">
                    <?php foreach (['breakfast' => 'Kahvaltı', 'lunch' => 'Öğle yemeği', 'dinner' => 'Akşam yemeği', 'snack' => 'Ara öğün', 'pre_workout' => 'Antrenman öncesi', 'post_workout' => 'Antrenman sonrası'] as $k => $l): ?>
                        <option value="<?= $k ?>" <?= $k === $qaDefaultMeal ? 'selected' : '' ?>><?= $l ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <!-- Yazarak (yapay zeka) -->
            <div data-qa-panel="text">
                <label class="form-label">Ne yediniz?</label>
                <textarea id="geminiInput" class="form-control" rows="3" maxlength="1000" placeholder="ör. 2 yumurta, 1 dilim tam buğday ekmek, 1 bardak süt"></textarea>
                <div class="form-text">Miktar ve pişirme şeklini yazarsanız sonuç daha doğru olur.</div>
                <button type="button" class="btn btn-outline-primary w-100 mt-3" id="previewBtn"><i class="bi bi-stars me-1"></i>Besin değerlerini hesapla</button>
            </div>

            <!-- Fotoğraf -->
            <div data-qa-panel="photo" hidden>
                <input type="file" id="cameraFileInput" accept="image/*" capture="environment" hidden>
                <input type="file" id="galleryFileInput" accept="image/*" hidden>
                <div id="photoDropArea" class="qa-drop">
                    <div style="font-size:34px">📸</div>
                    <div class="fw-semibold mt-1">Tabağınızın fotoğrafını çekin</div>
                    <div class="small mb-3" style="color:var(--muted)">Yapay zeka yiyecekleri tanıyıp kaloriyi hesaplar</div>
                    <div class="d-flex justify-content-center gap-2 flex-wrap">
                        <button type="button" class="btn btn-primary" onclick="document.getElementById('cameraFileInput').click()"><i class="bi bi-camera me-1"></i>Kamera</button>
                        <button type="button" class="btn btn-light" onclick="document.getElementById('galleryFileInput').click()"><i class="bi bi-image me-1"></i>Galeri</button>
                    </div>
                </div>
                <div id="photoPreviewContainer" hidden>
                    <div class="d-flex gap-3 align-items-start flex-wrap flex-sm-nowrap">
                        <div style="position:relative;flex-shrink:0">
                            <img id="photoPreviewImg" src="" alt="" style="width:132px;height:132px;object-fit:cover;border-radius:14px;border:1px solid var(--border)">
                            <button type="button" class="icon-btn" style="position:absolute;bottom:6px;right:6px" id="qaPhotoReset" title="Değiştir"><i class="bi bi-arrow-repeat"></i></button>
                        </div>
                        <div class="flex-grow-1" style="min-width:200px">
                            <label class="form-label">Not (isteğe bağlı)</label>
                            <input type="text" id="photoUserNotes" class="form-control" placeholder="ör. yarısını yedim, zeytinyağlı">
                            <button type="button" id="startPhotoAnalysisBtn" class="btn btn-outline-primary w-100 mt-3"><i class="bi bi-stars me-1"></i>Analiz et</button>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Sık yenenler -->
            <div data-qa-panel="frequent" hidden>
                <div id="qaFrequentList"><div class="small" style="color:var(--muted)">Yükleniyor…</div></div>
            </div>

            <!-- Elle -->
            <div data-qa-panel="manual" hidden>
                <div class="small mb-2" style="color:var(--muted)">Paket üzerindeki değerleri veya bildiğiniz değerleri girin.</div>
            </div>

            <!-- Takviye -->
            <div data-qa-panel="supp" hidden>
                <div class="input-group mb-3">
                    <span class="input-group-text"><i class="bi bi-search"></i></span>
                    <input type="text" id="suppSearchInput" class="form-control" placeholder="Takviye ara (ör. whey, kreatin)">
                </div>
                <div id="suppResultsModal" style="max-height:280px;overflow-y:auto"></div>
            </div>

            <!-- Yükleniyor / hata -->
            <div id="qaLoading" class="text-center py-4" hidden><div class="spinner-border spinner-border-sm me-2" style="color:var(--accent)"></div><span style="color:var(--muted)" id="qaLoadingText">Hesaplanıyor…</span></div>
            <div id="qaError" class="alert alert-danger mt-3 mb-0" hidden><i class="bi bi-exclamation-circle me-1"></i><span id="qaErrorMsg"></span></div>

            <!-- Ortak düzenleyici: yemek adı + makrolar -->
            <div id="qaEditor" class="mt-3" hidden>
                <label class="form-label">Yemek</label>
                <input type="text" id="qaLabel" class="form-control mb-2" maxlength="190" placeholder="ör. Tavuk döner dürüm">
                <div id="qaDesc" class="small mb-2" style="color:var(--muted)"></div>
                <div class="qa-macro-grid">
                    <div class="qa-cell" style="--mc:var(--c-kcal)"><div class="l">Kalori (kcal)</div><input type="number" inputmode="decimal" min="0" id="qaCal" class="form-control form-control-sm"></div>
                    <div class="qa-cell" style="--mc:var(--c-protein)"><div class="l">Protein (g)</div><input type="number" inputmode="decimal" min="0" step="0.1" id="qaProt" class="form-control form-control-sm"></div>
                    <div class="qa-cell" style="--mc:var(--c-carb)"><div class="l">Karb (g)</div><input type="number" inputmode="decimal" min="0" step="0.1" id="qaCarb" class="form-control form-control-sm"></div>
                    <div class="qa-cell" style="--mc:var(--c-fat)"><div class="l">Yağ (g)</div><input type="number" inputmode="decimal" min="0" step="0.1" id="qaFat" class="form-control form-control-sm"></div>
                </div>
                <div class="form-text" id="qaHint"><i class="bi bi-info-circle me-1"></i>Değerleri kaydetmeden önce düzeltebilirsiniz.</div>
            </div>
        </div>
        <div class="modal-footer">
            <button type="button" class="btn btn-light" data-bs-dismiss="modal">Vazgeç</button>
            <button type="button" class="btn btn-primary px-4" id="addBtn" disabled><i class="bi bi-plus-lg me-1"></i>Günlüğe ekle</button>
        </div>
    </div>
  </div>
</div>

<script>
(function () {
    'use strict';
    const $ = id => document.getElementById(id);
    const esc = s => String(s ?? '').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;').replace(/'/g,'&#039;');
    const n0 = v => Math.round(parseFloat(v || 0)).toLocaleString('tr-TR');
    const api = (file, params) => {
        const fd = params instanceof FormData ? params : Object.entries(params).reduce((f, [k, v]) => (f.append(k, v), f), new FormData());
        return fetch(`${window.API_BASE}/${file}`, { method: 'POST', body: fd, credentials: 'include' }).then(r => r.json());
    };
    const todayISO = () => { const d = new Date(); return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`; };

    let tab = 'text';
    let logDate = todayISO();
    let supp = null;
    let photo = null;
    let suppResults = [];
    let freqItems = null;

    function show(id, on) { const e = $(id); if (e) e.hidden = !on; }
    function setEditor(v, desc) {
        $('qaLabel').value = v.label || '';
        $('qaCal').value = v.cal ?? '';
        $('qaProt').value = v.prot ?? '';
        $('qaCarb').value = v.carb ?? '';
        $('qaFat').value = v.fat ?? '';
        $('qaDesc').textContent = desc || '';
        show('qaEditor', true);
        refresh();
    }
    function clearEditor() { ['qaLabel', 'qaCal', 'qaProt', 'qaCarb', 'qaFat'].forEach(i => $(i).value = ''); $('qaDesc').textContent = ''; show('qaEditor', false); }
    function refresh() {
        const btn = $('addBtn');
        if (tab === 'supp') btn.disabled = !supp;
        else btn.disabled = $('qaEditor').hidden || !$('qaLabel').value.trim() || $('qaCal').value === '';
    }
    ['qaLabel', 'qaCal', 'qaProt', 'qaCarb', 'qaFat'].forEach(i => $(i).addEventListener('input', refresh));

    function setTab(t) {
        tab = t;
        document.querySelectorAll('[data-qa]').forEach(b => b.classList.toggle('active', b.dataset.qa === t));
        document.querySelectorAll('[data-qa-panel]').forEach(p => p.hidden = p.dataset.qaPanel !== t);
        show('qaError', false); show('qaLoading', false);
        supp = null;
        show('qaMealWrap', t !== 'supp');
        if (t === 'manual') { clearEditor(); setEditor({}); $('qaLabel').focus(); }
        else clearEditor();
        if (t === 'frequent') loadFrequent();
        refresh();
    }
    document.querySelectorAll('[data-qa]').forEach(b => b.addEventListener('click', () => setTab(b.dataset.qa)));

    function fail(msg) { $('qaErrorMsg').textContent = msg; show('qaError', true); }
    function loading(on, text) { $('qaLoadingText').textContent = text || 'Hesaplanıyor…'; show('qaLoading', on); }

    /* Yazarak */
    $('previewBtn').addEventListener('click', async () => {
        const text = $('geminiInput').value.trim();
        if (!text) { $('geminiInput').focus(); return; }
        show('qaError', false); clearEditor(); loading(true);
        $('previewBtn').disabled = true;
        try {
            const d = await api('analyze_food.php', { action: 'analyze_only', meal_text: text });
            if (!d.ok) throw new Error(d.error || 'Analiz yapılamadı');
            const m = d.analyzed.macros;
            setEditor({ label: text.length > 120 ? text.slice(0, 117) + '…' : text, cal: m.kalori, prot: m.protein, carb: m.karb, fat: m.yag }, 'Yapay zeka tahmini');
        } catch (e) { fail(e.message); } finally { loading(false); $('previewBtn').disabled = false; }
    });

    /* Fotoğraf */
    async function compress(file, maxDim = 1400, q = .82) {
        if (!file || !file.type.startsWith('image/')) return file;
        return new Promise(res => {
            const img = new Image();
            img.onload = () => {
                let w = img.width, h = img.height;
                if (w <= maxDim && h <= maxDim && file.size < 1048576) { res(file); return; }
                const s = Math.min(1, maxDim / Math.max(w, h)); w = Math.round(w * s); h = Math.round(h * s);
                const c = document.createElement('canvas'); c.width = w; c.height = h;
                c.getContext('2d').drawImage(img, 0, 0, w, h);
                c.toBlob(b => res(b ? new File([b], 'meal.jpg', { type: 'image/jpeg' }) : file), 'image/jpeg', q);
            };
            img.onerror = () => res(file);
            img.src = URL.createObjectURL(file);
        });
    }
    async function onPhoto(input) {
        if (!input.files || !input.files[0]) return;
        const f = await compress(input.files[0]);
        if (f.size > 10 * 1048576) { fail('Fotoğraf 10 MB\'dan küçük olmalı.'); return; }
        photo = f;
        $('photoPreviewImg').src = URL.createObjectURL(f);
        show('photoDropArea', false); show('photoPreviewContainer', true); clearEditor(); show('qaError', false);
    }
    $('cameraFileInput').addEventListener('change', e => onPhoto(e.target));
    $('galleryFileInput').addEventListener('change', e => onPhoto(e.target));
    function resetPhoto() {
        photo = null;
        ['cameraFileInput', 'galleryFileInput', 'photoUserNotes'].forEach(i => $(i).value = '');
        show('photoDropArea', true); show('photoPreviewContainer', false);
        if (tab === 'photo') clearEditor();
    }
    $('qaPhotoReset').addEventListener('click', resetPhoto);
    $('startPhotoAnalysisBtn').addEventListener('click', async () => {
        if (!photo) return;
        const btn = $('startPhotoAnalysisBtn'); btn.disabled = true;
        show('qaError', false); loading(true, 'Fotoğraf analiz ediliyor…');
        try {
            const fd = new FormData();
            fd.append('action', 'analyze_image');
            fd.append('food_image', photo);
            fd.append('meal_type', $('mealTypeSel').value);
            fd.append('notes', $('photoUserNotes').value.trim());
            const d = await api('analyze_food.php', fd);
            if (!d.ok) throw new Error(d.error || 'Analiz başarısız');
            const a = d.analyzed;
            setEditor({ label: a.food_label || 'Fotoğraflı öğün', cal: a.macros.kalori, prot: a.macros.protein, carb: a.macros.karb, fat: a.macros.yag }, a.description ? 'Tespit edilenler: ' + a.description : '');
        } catch (e) { fail(e.message); } finally { loading(false); btn.disabled = false; }
    });

    /* Sık yenenler */
    async function loadFrequent() {
        const el = $('qaFrequentList');
        if (!freqItems) {
            try { const d = await api('dashboard.php', { action: 'frequent_foods' }); freqItems = d.ok ? d.items : []; }
            catch (_) { freqItems = []; }
        }
        el.innerHTML = freqItems.length ? freqItems.map((f, i) => `
            <div class="qa-item" data-i="${i}">
                <div class="icon-tile sm"><i class="bi bi-arrow-repeat"></i></div>
                <div style="flex:1;min-width:0"><div class="fw-semibold text-truncate">${esc(f.food_label)}</div>
                <div class="small" style="color:var(--muted)">P ${n0(f.protein_g)} · K ${n0(f.carbs_g)} · Y ${n0(f.fat_g)} · ${f.count}×</div></div>
                <div class="kc">${n0(f.calories)} <small style="color:var(--muted);font-weight:500">kcal</small></div>
            </div>`).join('')
            : '<div class="empty-state py-3"><i class="bi bi-clock-history"></i>Henüz kayıt yok. Eklediğiniz yemekler burada listelenir.</div>';
        el.querySelectorAll('.qa-item').forEach(it => it.addEventListener('click', () => {
            el.querySelectorAll('.qa-item').forEach(x => x.classList.toggle('selected', x === it));
            const f = freqItems[+it.dataset.i];
            setEditor({ label: f.food_label, cal: f.calories, prot: f.protein_g, carb: f.carbs_g, fat: f.fat_g }, 'Önceki kayıtlarınızın ortalaması');
        }));
    }

    /* Takviye */
    let suppTimer = null;
    $('suppSearchInput').addEventListener('input', e => {
        clearTimeout(suppTimer);
        const q = e.target.value.trim();
        const el = $('suppResultsModal');
        if (q.length < 2) { el.innerHTML = ''; return; }
        suppTimer = setTimeout(async () => {
            const d = await api('dashboard.php', { action: 'quick_search', q });
            if (!d.ok) return;
            suppResults = d.supplements || [];
            el.innerHTML = suppResults.length ? suppResults.map((s, i) => `
                <div class="qa-item" data-i="${i}">
                    <div class="icon-tile sm"><i class="bi bi-capsule"></i></div>
                    <div style="flex:1;min-width:0"><div class="fw-semibold text-truncate">${esc(s.name)}</div><div class="small" style="color:var(--muted)">${esc(s.dose_amount)} ${esc(s.dose_unit)}</div></div>
                    <div class="kc">${n0(s.calories_per_dose)} <small style="color:var(--muted);font-weight:500">kcal</small></div>
                </div>`).join('')
                : '<div class="empty-state py-3">Takviye bulunamadı. <a href="reminders.php" style="color:var(--accent)">Ekle →</a></div>';
            el.querySelectorAll('.qa-item').forEach(it => it.addEventListener('click', () => {
                el.querySelectorAll('.qa-item').forEach(x => x.classList.toggle('selected', x === it));
                supp = suppResults[+it.dataset.i];
                refresh();
            }));
        }, 300);
    });

    /* Kaydet */
    $('addBtn').addEventListener('click', async () => {
        const btn = $('addBtn');
        const html = btn.innerHTML;
        btn.disabled = true;
        btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>Kaydediliyor…';
        try {
            let d;
            if (tab === 'supp') {
                d = await api('dashboard.php', { action: 'quick_add', source: 'local', supplement_id: supp.id });
            } else {
                d = await api('analyze_food.php', {
                    action: 'save_custom',
                    food_label: $('qaLabel').value.trim(),
                    meal_type: $('mealTypeSel').value,
                    calories: $('qaCal').value || 0,
                    protein: $('qaProt').value || 0,
                    carbs: $('qaCarb').value || 0,
                    fat: $('qaFat').value || 0,
                    log_date: logDate
                });
            }
            if (!d || !d.ok) throw new Error((d && d.error) || 'Kaydedilemedi');
            freqItems = null;
            bootstrap.Modal.getOrCreateInstance($('quickAddModal')).hide();
            if (window.Swal) Swal.fire({ toast: true, position: 'top', icon: 'success', title: 'Günlüğe eklendi', showConfirmButton: false, timer: 1800 });
            document.dispatchEvent(new CustomEvent('opti:food-added', { detail: { date: logDate } }));
        } catch (e) {
            fail(e.message);
        } finally {
            btn.innerHTML = html;
            refresh();
        }
    });

    $('quickAddModal').addEventListener('hidden.bs.modal', () => {
        $('geminiInput').value = '';
        $('suppSearchInput').value = '';
        $('suppResultsModal').innerHTML = '';
        resetPhoto();
        setTab('text');
    });

    function labelDate() {
        const t = todayISO();
        $('qaDateLabel').textContent = logDate === t ? 'Bugün' : new Date(logDate + 'T00:00').toLocaleDateString('tr-TR', { weekday: 'long', day: 'numeric', month: 'long' });
    }
    $('quickAddModal').addEventListener('show.bs.modal', labelDate);

    window.OptiQuickAdd = {
        setDate(d) { logDate = /^\d{4}-\d{2}-\d{2}$/.test(d || '') ? d : todayISO(); labelDate(); },
        open(opts = {}) {
            if (opts.date) this.setDate(opts.date);
            bootstrap.Modal.getOrCreateInstance($('quickAddModal')).show();
            if (opts.tab) setTab(opts.tab);
            if (opts.meal) $('mealTypeSel').value = opts.meal;
        }
    };
    labelDate();
})();
</script>
