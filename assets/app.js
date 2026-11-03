/* =====================================================================================
   Computer-Based Test (CBT) — skrip halaman (menu, konfirmasi, impor berkas, mesin ujian)
   ===================================================================================== */
(function () {
  'use strict';

  var $ = function (s, k) { return (k || document).querySelector(s); };
  var $$ = function (s, k) { return Array.prototype.slice.call((k || document).querySelectorAll(s)); };

  /* --------------------------------------------------------------- menu HP */
  function initMenu() {
    var b = $('#btnMenu');
    if (!b) { return; }
    b.addEventListener('click', function (ev) {
      ev.stopPropagation();
      document.body.classList.toggle('menu-on');
    });
    document.addEventListener('click', function (ev) {
      if (!document.body.classList.contains('menu-on')) { return; }
      if (ev.target.closest && ev.target.closest('.sidebar')) { return; }
      if (ev.target === b || (b.contains && b.contains(ev.target))) { return; }
      document.body.classList.remove('menu-on');
    });
  }

  /* --------------------------------------------------------- halaman depan (landing) */
  // v1.14.0: initLanding(), initTestimoni(), initKartuTimbul(), dan initLandingAdmin() DIHAPUS
  // bersama halaman landing page (permintaan pemilik). Halaman publik aplikasi kini hanya
  // halaman masuk (.masuk-*) yang tidak memerlukan skrip khusus.

  /* --------------------------------------------------------------- konfirmasi */
  function initConfirm() {
    document.addEventListener('submit', function (ev) {
      var f = ev.target;
      if (!f || !f.getAttribute) { return; }
      // Pesan konfirmasi boleh dipasang pada FORM maupun pada TOMBOL pengirimnya
      // (tombol diperiksa lebih dulu agar satu formulir boleh punya beberapa tombol
      // dengan peringatan berbeda).
      var pesan = '';
      var b = ev.submitter || null;
      if (b && b.getAttribute) { pesan = b.getAttribute('data-confirm') || ''; }
      if (!pesan) { pesan = f.getAttribute('data-confirm') || ''; }
      if (pesan && !window.confirm(pesan)) { ev.preventDefault(); }
    });
  }

  /* --------------------------------------------------------------- salin teks */
  function initSalin() {
    $$('[data-salin]').forEach(function (b) {
      b.addEventListener('click', function () {
        var sel = b.getAttribute('data-salin');
        var el = sel && sel.charAt(0) === '#' ? document.querySelector(sel) : null;
        var teks = el ? (el.textContent || '') : (b.getAttribute('data-teks') || '');
        teks = teks.trim();
        if (!teks) { return; }
        var selesai = function () {
          var asal = b.textContent;
          b.textContent = 'Tersalin';
          setTimeout(function () { b.textContent = asal; }, 1400);
        };
        if (navigator.clipboard && navigator.clipboard.writeText) {
          navigator.clipboard.writeText(teks).then(selesai, function () { fallback(teks, selesai); });
        } else {
          fallback(teks, selesai);
        }
      });
    });
  }
  function fallback(teks, cb) {
    var t = document.createElement('textarea');
    t.value = teks; document.body.appendChild(t); t.select();
    try { document.execCommand('copy'); } catch (e) { /* abaikan */ }
    document.body.removeChild(t);
    if (cb) { cb(); }
  }

  /* --------------------------------------------------------------- unggah foto (pratinjau) */
  function initFotoPratinjau() {
    var inp = $('#fileLogo');
    if (!inp) { return; }
    inp.addEventListener('change', function () {
      var f = inp.files && inp.files[0];
      if (!f) { return; }
      var maks = 15 * 1024 * 1024;
      var kotakPesan = $('#pesanLogo');
      if (f.size > maks) {
        if (kotakPesan) { kotakPesan.textContent = 'Ukuran gambar maksimal 15 MB.'; }
        inp.value = ''; return;
      }
      if (kotakPesan) { kotakPesan.textContent = ''; }
      var baca = new FileReader();
      baca.onload = function (e) {
        var img = $('#pratinjauLogo');
        if (img) { img.src = e.target.result; img.hidden = false; }
      };
      baca.readAsDataURL(f);
    });
  }

  /* --------------------------------------------------------------- impor berkas (SheetJS) */
  function initImpor() {
    var inp = $('#fileImpor');
    if (!inp) { return; }
    var kolom = [];
    try { kolom = JSON.parse(inp.getAttribute('data-kolom') || '[]'); } catch (e) { kolom = []; }
    var target = $('#payloadImpor');
    var kotak = $('#pratinjauImpor');
    var info = $('#infoImpor');
    var tombol = $('#btnKirimImpor');

    inp.addEventListener('change', function () {
      var f = inp.files && inp.files[0];
      if (!f) { return; }
      if (typeof XLSX === 'undefined') {
        if (info) { info.textContent = 'Pustaka pembaca berkas belum termuat. Muat ulang halaman.'; }
        return;
      }
      if (info) { info.textContent = 'Membaca berkas...'; }
      var reader = new FileReader();
      reader.onload = function (e) {
        try {
          var wb = XLSX.read(new Uint8Array(e.target.result), { type: 'array' });
          var ws = wb.Sheets[wb.SheetNames[0]];
          var baris = XLSX.utils.sheet_to_json(ws, { header: 1, defval: '', blankrows: false });
          baris = baris.filter(function (r) { return r.some(function (c) { return String(c).trim() !== ''; }); });
          if (!baris.length) {
            if (info) { info.textContent = 'Berkas kosong atau tidak terbaca.'; }
            return;
          }
          // Baris pertama dianggap judul kolom bila salah satu selnya cocok dengan kolom yang diharapkan.
          var pertama = baris[0].map(function (c) { return norm(String(c)); });
          var adaJudul = kolom.some(function (k) { return pertama.indexOf(k) !== -1; });
          var head = adaJudul ? baris[0] : [];
          var data = adaJudul ? baris.slice(1) : baris;
          if (target) { target.value = JSON.stringify({ judul: head, baris: data }); }
          gambarPratinjau(head, data, kolom, kotak, f.name, info);
          if (tombol) { tombol.disabled = data.length === 0; }
        } catch (err) {
          if (info) { info.textContent = 'Gagal membaca berkas: ' + err.message; }
        }
      };
      reader.readAsArrayBuffer(f);
    });

    function norm(s) {
      return s.toLowerCase().replace(/[^a-z0-9]+/g, '');
    }

    function gambarPratinjau(head, data, kolom, kotak, nama, info) {
      if (!kotak) { return; }
      var maks = 12;
      var h = '<div class="card-head"><h3>Pratinjau</h3><div class="sub">' + esc(nama) + ' &middot; ' + data.length + ' baris terbaca</div></div>';
      h += '<div class="table-wrap"><table class="tbl"><thead><tr><th>#</th>';
      kolom.forEach(function (k) { h += '<th>' + esc(k) + '</th>'; });
      h += '</tr></thead><tbody>';
      data.slice(0, maks).forEach(function (r, i) {
        h += '<tr><td>' + (i + 1) + '</td>';
        kolom.forEach(function (k, c) {
          h += '<td>' + esc(String(r[c] === undefined ? '' : r[c]).slice(0, 60)) + '</td>';
        });
        h += '</tr>';
      });
      h += '</tbody></table></div>';
      if (data.length > maks) { h += '<div class="card-body mut">Menampilkan ' + maks + ' baris pertama dari ' + data.length + ' baris.</div>'; }
      kotak.innerHTML = h;
      kotak.hidden = false;
      if (info) {
        info.innerHTML = '<b>' + data.length + ' baris</b> siap diimpor.' + (head.length ? '' : ' <span class="mut">(berkas tanpa baris judul — kolom dibaca berurutan)</span>');
      }
    }
    function esc(s) { return String(s).replace(/[&<>"]/g, function (c) { return ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' })[c]; }); }
  }

  /* --------------------------------------------------------------- mesin ujian */
  function initUjian() {
    var akar = $('#ujianApp');
    if (!akar) { return; }

    var sesiId = akar.getAttribute('data-sesi');
    var csrf = akar.getAttribute('data-csrf');
    var batas = parseInt(akar.getAttribute('data-batas') || '3', 10);
    var pelanggaran = parseInt(akar.getAttribute('data-pelanggaran') || '0', 10);
    var berkahir = parseInt(akar.getAttribute('data-berakhir') || '0', 10);
    var sekarang = parseInt(akar.getAttribute('data-sekarang') || '0', 10);
    var dimulai = Date.now();
    var selesaiKirim = false;

    /* ---- hitung mundur (acuan waktu SERVER, bukan jam perangkat) */
    var kotakTimer = $('#timerUjian');
    function detikSisa() {
      var lewat = Math.floor((Date.now() - dimulai) / 1000);
      return Math.max(0, berkahir - (sekarang + lewat));
    }
    function gambarTimer() {
      if (!kotakTimer) { return; }
      var s = detikSisa();
      var j = Math.floor(s / 3600), m = Math.floor((s % 3600) / 60), d = s % 60;
      var p2 = function (n) { return (n < 10 ? '0' : '') + n; };
      var teks = (j > 0 ? j + ':' : '') + p2(m) + ':' + p2(d);
      kotakTimer.innerHTML = '<span class="ico">&#9201;</span><b>' + teks + '</b>';
      kotakTimer.className = 'timer' + (s <= 60 ? ' habis' : (s <= 300 ? ' hampir' : ''));
      if (s <= 0 && !selesaiKirim) {
        selesaiKirim = true;
        kumpulkan('Waktu ujian sudah habis. Jawaban Anda dikumpulkan otomatis.');
      }
    }
    gambarTimer();
    setInterval(gambarTimer, 1000);

    /* ---- layar penuh + deteksi pindah tab */
    var pembuka = $('#layarMasukUjian');
    var btnMasuk = $('#btnMulaiUjian');
    if (btnMasuk) {
      btnMasuk.addEventListener('click', function () {
        mintaLayarPenuh();
        if (pembuka) { pembuka.classList.remove('on'); }
        pantau(true);
      });
    } else {
      pantau(true);
    }

    function mintaLayarPenuh() {
      var el = document.documentElement;
      var fn = el.requestFullscreen || el.webkitRequestFullscreen || el.msRequestFullscreen;
      if (fn) { try { fn.call(el); } catch (e) { /* diabaikan */ } }
    }

    var pantauAktif = false;
    function pantau(on) { pantauAktif = on; }

    document.addEventListener('visibilitychange', function () {
      if (!pantauAktif || document.hidden === false) { return; }
      catatPelanggaran('Berpindah tab atau meminimalkan jendela ujian');
    });
    window.addEventListener('blur', function () {
      if (!pantauAktif) { return; }
      catatPelanggaran('Keluar dari jendela ujian');
    });
    document.addEventListener('fullscreenchange', function () {
      if (!pantauAktif) { return; }
      if (!document.fullscreenElement && !document.webkitFullscreenElement) {
        catatPelanggaran('Keluar dari mode layar penuh');
      }
    });

    function catatPelanggaran(sebab) {
      if (selesaiKirim) { return; }
      if (akar.getAttribute('data-pantau') !== '1') { return; }
      var data = new FormData();
      data.append('sesi', sesiId);
      data.append('sebab', sebab);
      data.append('csrf', csrf);
      fetch('pelanggaran.php', { method: 'POST', body: data, headers: { 'X-Requested-With': 'fetch' } })
        .then(function (r) { return r.json(); })
        .then(function (j) {
          if (!j || !j.ok) { return; }
          pelanggaran = j.pelanggaran;
          var kotak = $('#pelanggaranKe');
          if (kotak) { kotak.textContent = j.pelanggaran + ' dari ' + batas; }
          if (j.kumpulkan) {
            selesaiKirim = true;
            kumpulkan('Batas pelanggaran tercapai. Ujian dihentikan dan jawaban Anda dikumpulkan.');
            return;
          }
          tampilPeringatan(sebab, j.pelanggaran, batas);
        })
        .catch(function () { /* jaringan bermasalah — jangan ganggu siswa */ });
    }

    function tampilPeringatan(sebab, ke, maks) {
      var layar = $('#layarPelanggaran');
      if (!layar) { return; }
      var isi = $('#pesanPelanggaran');
      if (isi) {
        isi.innerHTML = '<b>' + esc(sebab) + '</b> terdeteksi. Ini pelanggaran ke-' + ke + ' dari ' + maks +
          '. Bila pelanggaran mencapai ' + maks + ', ujian Anda <b>otomatis dikumpulkan</b>.';
      }
      layar.classList.add('on');
      var b = $('#btnLanjutUjian');
      if (b) {
        b.onclick = function () {
          layar.classList.remove('on');
          mintaLayarPenuh();
        };
      }
    }
    function esc(s) { return String(s).replace(/[&<>"]/g, function (c) { return ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' })[c]; }); }

    /* ---- penyimpanan jawaban */
    var tersimpan = {};
    function kartuSoal() { return $$('.soal-card', akar); }

    function ambilJawaban(card) {
      var id = card.getAttribute('data-soal');
      var jenis = card.getAttribute('data-jenis');
      if (jenis === 'pg') {
        var p = $('input[name="soal_' + id + '"]:checked', card);
        return p ? String(p.value) : null;
      }
      if (jenis === 'pgk') {
        var arr = $$('input[name="soal_' + id + '[]"]:checked', card).map(function (x) { return String(x.value); });
        return arr.length ? arr : null;
      }
      if (jenis === 'bs' || jenis === 'jodoh') {
        var n = parseInt(card.getAttribute('data-jumlah') || '0', 10);
        var out = [], ada = false;
        for (var i = 0; i < n; i++) {
          var el = $('[name="soal_' + id + '_' + i + '"]:checked', card) || $('[name="soal_' + id + '_' + i + '"]', card);
          var v = el ? String(el.value) : '';
          if (v !== '') { ada = true; }
          out.push(v);
        }
        return ada ? out : null;
      }
      var t = $('[name="soal_' + id + '"]', card);
      var val = t ? String(t.value).trim() : '';
      return val === '' ? null : val;
    }

    function simpanSoal(card) {
      var id = card.getAttribute('data-soal');
      var jwb = ambilJawaban(card);
      var data = new FormData();
      data.append('sesi', sesiId);
      data.append('soal', id);
      data.append('jawaban', jwb === null ? '' : JSON.stringify(jwb));
      data.append('csrf', csrf);
      fetch('simpan_jawaban.php', { method: 'POST', body: data, headers: { 'X-Requested-With': 'fetch' } })
        .then(function (r) { return r.json(); })
        .then(function (j) {
          if (!j || !j.ok) { return; }
          tersimpan[id] = jwb !== null;
          tanda(card, jwb !== null);
          var k = $('#jumlahTerjawab');
          if (k) { k.textContent = j.terjawab; }
          var t = $('#jumlahTotal');
          if (t) { t.textContent = j.total; }
        })
        .catch(function () {
          var k = $('#simpanStatus');
          if (k) { k.textContent = 'Gagal menyimpan — periksa jaringan.'; }
        });
    }

    function tanda(card, ada) {
      var id = card.getAttribute('data-soal');
      var nav = $('.nav-grid a[data-nav="' + id + '"]', akar);
      if (nav) { nav.classList.toggle('dijawab', ada); }
    }

    kartuSoal().forEach(function (card) {
      var jenis = card.getAttribute('data-jenis');
      var id = card.getAttribute('data-soal');
      card.addEventListener('change', function () { simpanSoal(card); });
      card.addEventListener('input', function (ev) {
        if (ev.target && (ev.target.tagName === 'TEXTAREA' || ev.target.tagName === 'INPUT')) {
          clearTimeout(card._t);
          card._t = setTimeout(function () { simpanSoal(card); }, 900);
        }
      });
      if (jenis === 'pg' || jenis === 'pgk') {
        $$('input', card).forEach(function (inp) {
          inp.addEventListener('change', function () {
            $$('.opsi', card).forEach(function (o) {
              var c = $('input', o);
              o.classList.toggle('on', !!(c && c.checked));
            });
          });
        });
      }
      if (jenis === 'bs') {
        $$('.bs-pil input', card).forEach(function (inp) {
          inp.addEventListener('change', function () {
            var grup = inp.name;
            $$('[name="' + grup + '"]', card).forEach(function (x) {
              if (x.parentNode) { x.parentNode.classList.toggle('on', x.checked); }
            });
          });
        });
      }
      // ragu-ragu (hanya penanda di layar)
      var btnRagu = $('.btn-ragu', card);
      if (btnRagu) {
        if (localStorage.getItem('ragu_' + sesiId + '_' + id) === '1') { btnRagu.classList.add('btn-utama'); }
        btnRagu.addEventListener('click', function () {
          var on = btnRagu.classList.toggle('btn-utama');
          localStorage.setItem('ragu_' + sesiId + '_' + id, on ? '1' : '0');
          var nav = $('.nav-grid a[data-nav="' + id + '"]', akar);
          if (nav) { nav.classList.toggle('ragu', on); }
        });
      }
      // tandai awal
      if (card.querySelector('input:checked')) { tanda(card, true); }
      else { var v = ambilJawaban(card); tanda(card, v !== null); }
    });

    /* ---- simpan semua & kumpulkan */
    // Jalur CADANGAN yang selalu bekerja: formulir biasa ke selesai.php (csrf + sesi + lanjut=1).
    // Dipakai bila permintaan fetch menggantung atau gagal — pengumpulan jawaban tidak boleh
    // bergantung pada fetch/JSON saja.
    var formDarurat = $('#formKumpulDarurat');
    function kirimLewatForm() {
      if (formDarurat) { formDarurat.submit(); return true; }
      window.location.href = 'selesai.php?sesi=' + encodeURIComponent(sesiId);
      return false;
    }

    function simpanSemua(cb) {
      var daftar = kartuSoal();
      if (!daftar.length) { cb(); return; }
      var sisa = daftar.length;
      var sudah = false;
      var beres = function () {
        if (sudah) { return; }   // panggil cb HANYA sekali
        sudah = true;
        cb();
      };
      // Jaring pengaman: bila ada permintaan yang tidak pernah selesai (jaringan putus), siswa
      // TIDAK boleh menggantung di layar "Mengumpulkan jawaban" — lanjutkan setelah batas waktu.
      var penjaga = setTimeout(beres, 8000);
      daftar.forEach(function (c) {
        var id = c.getAttribute('data-soal');
        var jwb = ambilJawaban(c);
        var data = new FormData();
        data.append('sesi', sesiId);
        data.append('soal', id);
        data.append('jawaban', jwb === null ? '' : JSON.stringify(jwb));
        data.append('csrf', csrf);
        fetch('simpan_jawaban.php', { method: 'POST', body: data, headers: { 'X-Requested-With': 'fetch' } })
          .catch(function () { /* diabaikan */ })
          .then(function () { sisa--; if (sisa <= 0) { clearTimeout(penjaga); beres(); } });
      });
    }

    function kumpulkan(pesan) {
      if (window.__sudahKumpul) { return; }
      window.__sudahKumpul = true;
      selesaiKirim = true;   // lepaskan penjaga "belum selesai" begitu pengumpulan dimulai
      pantau(false);
      tutupKumpul();
      tampilKunci(pesan || 'Mengumpulkan jawaban...');
      // Bila sampai batas waktu halaman belum berpindah (permintaan menggantung), pakai formulir biasa.
      var darurat = setTimeout(kirimLewatForm, 12000);
      simpanSemua(function () {
        var data = new FormData();
        data.append('sesi', sesiId);
        data.append('csrf', csrf);
        data.append('alasan', pesan || '');
        fetch('selesai.php', { method: 'POST', body: data, headers: { 'X-Requested-With': 'fetch' } })
          .then(function (r) { return r.json(); })
          .then(function (j) {
            clearTimeout(darurat);
            if (j && j.lanjut) { window.location.href = j.lanjut; return; }
            kirimLewatForm();
          })
          .catch(function () { clearTimeout(darurat); kirimLewatForm(); });
      });
    }

    function tampilKunci(pesan) {
      var layar = document.createElement('div');
      layar.className = 'layar-peringatan on';
      layar.innerHTML = '<div class="kotak-peringatan"><h2>Mengumpulkan jawaban</h2><p>' + esc(pesan) + '</p><p class="mut">Mohon tunggu, jangan menutup halaman ini.</p></div>';
      document.body.appendChild(layar);
    }

    /* ---- layar konfirmasi Kumpulkan (TANPA window.confirm) ----
       Peramban dapat menolak/menyembunyikan dialog confirm() — setelah pengguna mencentang
       "jangan tampilkan lagi dialog dari halaman ini", confirm() langsung mengembalikan false
       sehingga tombol Kumpulkan tampak tidak merespon sama sekali. Layar di dalam halaman ini
       tidak dapat diblokir peramban. */
    var layarKumpul = $('#layarKumpulkan');
    var pesanKumpul = $('#pesanKumpulkan');
    function bukaKumpul() {
      if (window.__sudahKumpul) { return; }
      if (!layarKumpul) { kumpulkan('Jawaban dikumpulkan oleh siswa.'); return; }
      var kosong = 0;
      kartuSoal().forEach(function (c) { if (ambilJawaban(c) === null) { kosong++; } });
      if (pesanKumpul) {
        pesanKumpul.innerHTML = kosong > 0
          ? 'Masih ada <b>' + kosong + ' soal</b> yang belum dijawab. Jawaban yang sudah Anda isi tetap tersimpan.'
          : 'Seluruh soal sudah Anda jawab.';
      }
      layarKumpul.classList.add('on');
    }
    function tutupKumpul() {
      if (layarKumpul) { layarKumpul.classList.remove('on'); }
    }
    var btnKumpul = $('#btnKumpulkan');
    if (btnKumpul) { btnKumpul.addEventListener('click', bukaKumpul); }
    var btnYaKumpul = $('#btnYaKumpulkan');
    if (btnYaKumpul) {
      btnYaKumpul.addEventListener('click', function () {
        kumpulkan('Jawaban dikumpulkan oleh siswa.');
      });
    }
    var btnBatalKumpul = $('#btnBatalKumpulkan');
    if (btnBatalKumpul) { btnBatalKumpul.addEventListener('click', tutupKumpul); }

    // Simpan otomatis berkala (jaring pengaman bila jaringan sempat putus)
    setInterval(function () { if (!selesaiKirim) { simpanSemua(function () { }); } }, 60000);

    // Cegah keluar halaman tanpa sengaja
    window.addEventListener('beforeunload', function (ev) {
      if (selesaiKirim) { return; }
      ev.preventDefault();
      ev.returnValue = '';
    });

    if (akar.getAttribute('data-pantau') === '1' && !btnMasuk) { pantau(true); }
  }

  /* --------------------------------------------------------------- HITUNG MUNDUR MASA AKTIF
     Masa aktif sekolah 365 hari harus terlihat berjalan (permintaan pemilik: "...harusnya
     365 hari (dan hitung mundur)").

     Acuannya WAKTU SERVER, bukan jam perangkat pengguna:
       data-berakhir = timestamp akhir masa aktif (dari basis data)
       data-sekarang = waktu server saat halaman dirender
     sisa = data-berakhir - (data-sekarang + detik yang berlalu sejak halaman dibuka).

     Dua bentuk didukung:
       1. blok lengkap [data-hitung-mundur] (halaman Status & Aktivasi) — Hari/Jam/Menit/Detik;
       2. baris ringkas [data-hm-mini] (kartu dashboard) — teks "Sisa N hari HH:MM:SS". */
  function initHitungMundur() {
    var blok = $$('[data-hitung-mundur]');
    var mini = $$('[data-hm-mini]');
    if (!blok.length && !mini.length) { return; }

    function dua(n) { return (n < 10 ? '0' : '') + n; }

    function sisaDari(el) {
      var akhir = parseInt(el.getAttribute('data-berakhir'), 10);
      var dasar = parseInt(el.getAttribute('data-sekarang'), 10);
      if (!isFinite(akhir) || !isFinite(dasar) || akhir <= 0) { return null; }
      var lewat = Math.floor((Date.now() - awal) / 1000);
      var sisa = akhir - (dasar + lewat);
      return sisa > 0 ? sisa : 0;
    }

    var awal = Date.now();

    // Blok lengkap
    blok.forEach(function (kotak) {
      var simpul = {
        hari: kotak.querySelector('[data-hm="hari"]'),
        jam: kotak.querySelector('[data-hm="jam"]'),
        menit: kotak.querySelector('[data-hm="menit"]'),
        detik: kotak.querySelector('[data-hm="detik"]')
      };
      var ket = kotak.querySelector('.hitung-mundur-ket');
      var sudahSelesai = false;
      function gambar() {
        var sisa = sisaDari(kotak);
        if (sisa === null) { return; }
        var hari = Math.floor(sisa / 86400);
        var jam = Math.floor((sisa % 86400) / 3600);
        var menit = Math.floor((sisa % 3600) / 60);
        var detik = sisa % 60;
        if (simpul.hari) { simpul.hari.textContent = String(hari); }
        if (simpul.jam) { simpul.jam.textContent = dua(jam); }
        if (simpul.menit) { simpul.menit.textContent = dua(menit); }
        if (simpul.detik) { simpul.detik.textContent = dua(detik); }
        if (ket) { ket.textContent = 'Sisa ' + hari + ' hari ' + dua(jam) + ':' + dua(menit) + ':' + dua(detik); }
        if (sisa <= 0 && !sudahSelesai) {
          sudahSelesai = true;
          kotak.classList.add('habis');
          if (ket) { ket.textContent = 'Masa aktif baru saja berakhir — memuat ulang halaman...'; }
          // Muat ulang SEKALI supaya penguncian (gerbang_aktivasi) langsung berlaku.
          window.setTimeout(function () { window.location.reload(); }, 2500);
        }
      }
      gambar();
      window.setInterval(gambar, 1000);
    });

    // Baris ringkas pada kartu dashboard
    mini.forEach(function (el) {
      function gambar() {
        var sisa = sisaDari(el);
        if (sisa === null) { return; }
        var hari = Math.floor(sisa / 86400);
        var jam = Math.floor((sisa % 86400) / 3600);
        var menit = Math.floor((sisa % 3600) / 60);
        var detik = sisa % 60;
        el.textContent = 'Sisa ' + hari + ' hari ' + dua(jam) + ':' + dua(menit) + ':' + dua(detik);
        if (sisa <= 0) { el.classList.add('habis'); }
      }
      gambar();
      window.setInterval(gambar, 1000);
    });
  }

  /* --------------------------------------------------------------- mulai */
  document.addEventListener('DOMContentLoaded', function () {
    initMenu();
    initConfirm();
    initSalin();
    initFotoPratinjau();
    initImpor();
    initUjian();
    initHitungMundur();
  });
})();
