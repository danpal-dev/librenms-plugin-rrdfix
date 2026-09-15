@php
  $fb = class_exists('\App\Plugins\FlowbiteTheme\FlowbiteTheme') || $fbActive = (static function() { $c = \App\Models\Plugin::where('plugin_name','FlowbiteTheme')->value('plugin_active'); return $c == '1'; })();
@endphp
<div id="rrdfix-root" class="container-fluid py-4 rrdfix-flowbite">
    <style>
        /* =========================================================
           RrdFix · Flowbite-first theme layer (Bootstrap 3 friendly)
           Si FlowbiteTheme está activo (html.fb-active / body.fb-layout)
           hereda variables --fb-primary / --fb-font-family del core.
           Si no, usa unos fallback azules neutros que pegan con LibreNMS.
           ========================================================= */
        :root {
            --rrdfx-primary:       var(--fb-primary,       #2563eb);
            --rrdfx-primary-hover: var(--fb-primary-hover, #1d4ed8);
            --rrdfx-accent:        var(--fb-brand-500,     #3b82f6);
            --rrdfx-success:       #059669;
            --rrdfx-danger:        #dc2626;
            --rrdfx-warning:       #d97706;
            --rrdfx-muted:         #64748b;
            --rrdfx-border:        var(--fb-border,        #e2e8f0);
            --rrdfx-surface:       var(--fb-surface,       #ffffff);
            --rrdfx-bg-muted:      var(--fb-surface-muted, #f8fafc);
            --rrdfx-font:          var(--fb-font-family,   ui-sans-serif, system-ui, -apple-system, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif);
        }

        .rrdfix-flowbite {
            font-family: var(--rrdfx-font);
            color: #0f172a;
        }
        .rrdfix-flowbite h2.rrdfix-title {
            font-size: 1.75rem;
            font-weight: 700;
            letter-spacing: -0.01em;
            margin: 0 0 1.25rem;
            color: #0f172a;
            display: flex; align-items: center; gap: .7rem;
        }
        .rrdfix-flowbite h2.rrdfix-title .rrdfix-icon-wrap {
            display: inline-flex; align-items: center; justify-content: center;
            width: 2.5rem; height: 2.5rem; border-radius: .75rem;
            background: linear-gradient(135deg, var(--rrdfx-primary), var(--rrdfx-accent));
            color: #fff; box-shadow: 0 6px 18px -6px rgba(37,99,235,.45);
        }
        .rrdfix-flowbite .rrdfix-version {
            margin-left: auto;
            font-size: .75rem;
            font-weight: 600;
            color: var(--rrdfx-muted);
            background: var(--rrdfx-bg-muted);
            padding: .25rem .6rem;
            border-radius: 9999px;
            border: 1px solid var(--rrdfx-border);
        }

        /* ---------- Cards ---------- */
        .rrdfix-card {
            background: var(--rrdfx-surface);
            border: 1px solid var(--rrdfx-border);
            border-radius: .85rem;
            box-shadow: 0 1px 2px rgba(15,23,42,.04), 0 1px 3px rgba(15,23,42,.04);
            margin-bottom: 1.25rem;
            overflow: hidden;
        }
        .rrdfix-card .rrdfix-card-head {
            padding: 1rem 1.25rem;
            border-bottom: 1px solid var(--rrdfx-border);
            background: linear-gradient(180deg, var(--rrdfx-bg-muted) 0%, #fff 100%);
            display: flex; align-items: center; gap: .75rem;
        }
        .rrdfix-card .rrdfix-card-head h3 {
            margin: 0; font-size: 1.02rem; font-weight: 700; color: #0f172a;
        }
        .rrdfix-card .rrdfix-card-head .rrdfix-step-badge {
            display: inline-flex; align-items: center; justify-content: center;
            width: 1.8rem; height: 1.8rem; border-radius: 9999px;
            background: var(--rrdfx-primary); color: #fff;
            font-weight: 700; font-size: .85rem;
        }
        .rrdfix-card .rrdfix-card-head .rrdfix-step-badge.secondary {
            background: var(--rrdfx-muted);
        }
        .rrdfix-card .rrdfix-card-head .rrdfix-muted-lead {
            color: var(--rrdfx-muted); font-size: .82rem;
            margin-left: .25rem;
        }
        .rrdfix-card .rrdfix-card-body {
            padding: 1.25rem;
        }
        .rrdfix-card.rrdfix-nested {
            border-radius: .65rem;
            background: var(--rrdfx-bg-muted);
            margin: .5rem 0 0;
        }
        .rrdfix-card.rrdfix-nested .rrdfix-card-head {
            background: transparent;
            border-bottom: none;
            padding: .75rem 1rem;
        }
        .rrdfix-card.rrdfix-nested .rrdfix-card-body {
            padding: .25rem 1rem 1rem;
        }

        /* ---------- Badges / labels ---------- */
        .rrdfx-badge {
            display: inline-flex; align-items: center; gap: .35rem;
            padding: .2rem .6rem; font-size: .72rem; font-weight: 700;
            border-radius: 9999px; border: 1px solid transparent;
            line-height: 1; letter-spacing: .01em;
        }
        .rrdfx-badge.info     { background: #e0f2fe; color: #0369a1; border-color:#bae6fd; }
        .rrdfx-badge.success  { background: #dcfce7; color: #166534; border-color:#bbf7d0; }
        .rrdfx-badge.warning  { background: #fef3c7; color: #92400e; border-color:#fde68a; }
        .rrdfx-badge.danger   { background: #fee2e2; color: #991b1b; border-color:#fecaca; }
        .rrdfx-badge.muted    { background: #f1f5f9; color: var(--rrdfx-muted); border-color:#e2e8f0; }

        /* ---------- Forms ---------- */
        .rrdfix-flowbite .form-control, .rrdfix-flowbite select.form-control, .rrdfix-flowbite input.form-control, .rrdfix-flowbite textarea.form-control {
            border-radius: .55rem !important;
            border: 1px solid var(--rrdfx-border);
            background: #fff;
            padding: .55rem .75rem;
            font-size: .92rem;
            transition: border-color .15s ease, box-shadow .15s ease;
            min-height: 2.3rem;
        }
        .rrdfix-flowbite .form-control:focus {
            border-color: var(--rrdfx-primary);
            box-shadow: 0 0 0 3px rgba(37,99,235,.15);
            outline: none;
        }
        .rrdfix-flowbite label {
            font-weight: 600;
            color: #0f172a;
            font-size: .9rem;
            margin-bottom: .35rem;
        }
        .rrdfix-flowbite .rrdfix-help {
            color: var(--rrdfx-muted);
            font-size: .8rem;
            margin-top: .25rem;
            display: block;
        }
        .rrdfix-flowbite .rrdfix-inline-note {
            color: var(--rrdfx-muted);
            font-size: .84rem;
            margin-left: .3rem;
        }
        .rrdfix-flowbite .rrdfix-check-row {
            display: flex; align-items: flex-start; gap: .55rem;
            padding: .75rem 0 0;
        }
        .rrdfix-flowbite input[type="checkbox"] {
            width: 1.05rem; height: 1.05rem; margin-top: .15rem;
            accent-color: var(--rrdfx-primary);
        }

        /* ---------- Buttons ---------- */
        .rrdfix-flowbite .btn.rrdfix-btn {
            border-radius: .6rem;
            padding: .6rem 1.1rem;
            font-weight: 700;
            letter-spacing: .01em;
            border: 1px solid transparent;
            display: inline-flex; align-items: center; gap: .5rem;
            transition: transform .1s ease, box-shadow .15s ease, background .15s ease, border-color .15s ease;
        }
        .rrdfix-flowbite .btn.rrdfix-btn-primary {
            background: linear-gradient(180deg, var(--rrdfx-primary) 0%, var(--rrdfx-primary-hover) 100%);
            color: #fff;
            border-color: var(--rrdfx-primary-hover);
            box-shadow: 0 6px 16px -6px rgba(37,99,235,.45);
        }
        .rrdfix-flowbite .btn.rrdfix-btn-primary:hover:not(:disabled) {
            background: var(--rrdfx-primary-hover);
            color: #fff;
            transform: translateY(-1px);
        }
        .rrdfix-flowbite .btn.rrdfix-btn-danger {
            background: linear-gradient(180deg, #ef4444 0%, var(--rrdfx-danger) 100%);
            color: #fff;
            border-color: var(--rrdfx-danger);
            box-shadow: 0 6px 16px -6px rgba(220,38,38,.4);
        }
        .rrdfix-flowbite .btn.rrdfix-btn-danger:hover:not(:disabled) {
            background: var(--rrdfx-danger);
            color: #fff;
            transform: translateY(-1px);
        }
        .rrdfix-flowbite .btn[disabled] {
            opacity: .65;
            cursor: not-allowed;
            box-shadow: none !important;
            transform: none !important;
        }

        /* ---------- Alerts ---------- */
        .rrdfix-flowbite .alert {
            border-radius: .65rem;
            border-width: 1px;
            padding: .9rem 1rem;
            font-weight: 500;
        }
        .rrdfix-flowbite .alert-info    { background:#eff6ff; color:#1d4ed8; border-color:#bfdbfe; }
        .rrdfix-flowbite .alert-success { background:#ecfdf5; color:#047857; border-color:#a7f3d0; }
        .rrdfix-flowbite .alert-warning { background:#fffbeb; color:#92400e; border-color:#fde68a; }
        .rrdfix-flowbite .alert-danger  { background:#fef2f2; color:#b91c1c; border-color:#fecaca; }

        /* ---------- Progress panel ---------- */
        .rrdfix-progress {
            border-radius: .85rem;
            border: 1px solid var(--rrdfx-border);
            overflow: hidden;
            margin-bottom: 1.25rem;
        }
        .rrdfix-progress .rrdfix-progress-head {
            display: flex; align-items: center; gap: .6rem;
            padding: .8rem 1.1rem;
            font-weight: 700;
        }
        .rrdfix-progress.info    .rrdfix-progress-head { background: #e0f2fe; color:#075985; }
        .rrdfix-progress.success .rrdfix-progress-head { background: #dcfce7; color:#166534; }
        .rrdfix-progress.default .rrdfix-progress-head { background: #f1f5f9; color:#334155; }
        .rrdfix-progress.info    { border-color:#bae6fd; }
        .rrdfix-progress.success { border-color:#bbf7d0; }
        .rrdfix-progress.default { border-color:#cbd5e1; }

        .rrdfix-progress .rrdfix-progress-body { padding: 1rem 1.1rem 1.2rem; background:#fff; }
        .rrdfix-progress .rrdfix-progress-bar {
            height: .85rem; border-radius: 9999px; overflow: hidden;
            background: #e2e8f0;
        }
        .rrdfix-progress .rrdfix-progress-bar > div {
            height: 100%; width: 100%;
            border-radius: 9999px;
            transition: width .4s ease, background-color .3s ease;
        }
        .rrdfix-progress.info    .rrdfix-progress-bar > div {
            background: repeating-linear-gradient(
                45deg,
                #0284c7 0 12px, #0ea5e9 12px 24px
            );
            background-size: 200% 200%;
            animation: rrdfx-stripe-move 1.2s linear infinite;
        }
        .rrdfix-progress.success .rrdfix-progress-bar > div { background: var(--rrdfx-success); }
        .rrdfix-progress.default .rrdfix-progress-bar > div { background: #cbd5e1; }
        @keyframes rrdfx-stripe-move {
            from { background-position: 0 0; }
            to   { background-position: 200% 0; }
        }
        .rrdfix-progress .rrdfix-metrics {
            display: grid;
            grid-template-columns: repeat(4, minmax(0, 1fr));
            gap: .75rem;
            margin-top: 1rem;
        }
        .rrdfix-progress .rrdfix-metric {
            background: var(--rrdfx-bg-muted);
            border: 1px solid var(--rrdfx-border);
            border-radius: .6rem;
            padding: .6rem .75rem;
            text-align: center;
        }
        .rrdfix-progress .rrdfix-metric small {
            display:block; color: var(--rrdfx-muted);
            font-size: .72rem; margin-bottom: .15rem;
        }
        .rrdfix-progress .rrdfix-metric strong {
            display:block; font-size: 1.02rem; color:#0f172a;
        }
        @media (max-width: 768px) {
            .rrdfix-progress .rrdfix-metrics { grid-template-columns: repeat(2, 1fr); }
        }

        /* ---------- Log ---------- */
        .rrdfix-log {
            background: #0b1220;
            color: #e2e8f0;
            border-radius: .65rem;
            padding: 1rem;
            font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, monospace;
            font-size: .83rem;
            line-height: 1.45;
            max-height: 520px;
            overflow: auto;
            border: 1px solid #1e293b;
            white-space: pre-wrap;
            word-break: break-word;
        }
        .rrdfix-log .rrdfix-empty { color: #475569; font-style: italic; }

        /* ---------- Accordion docs ---------- */
        .rrdfix-accordion summary {
            list-style: none;
            cursor: pointer;
            padding: .9rem 1.1rem;
            display: flex; align-items: center; gap: .6rem;
            background: var(--rrdfx-bg-muted);
            border-bottom: 1px solid var(--rrdfx-border);
            font-weight: 700;
            color: #0f172a;
            border-top-left-radius: .85rem;
            border-top-right-radius: .85rem;
        }
        .rrdfix-accordion[open] summary {
            border-bottom-left-radius: 0;
            border-bottom-right-radius: 0;
        }
        .rrdfix-accordion summary::-webkit-details-marker { display: none; }
        .rrdfix-accordion .rrdfix-acc-chevron {
            transition: transform .2s ease;
            margin-left: auto;
            color: var(--rrdfx-muted);
        }
        .rrdfix-accordion[open] .rrdfix-acc-chevron { transform: rotate(180deg); }
        .rrdfix-accordion .rrdfix-acc-body {
            padding: 1rem 1.25rem;
            background: #fff;
            font-size: .88rem;
            line-height: 1.55;
        }
        .rrdfix-docs-tabs {
            display: flex; flex-wrap: wrap; gap: .4rem;
            padding: .65rem 1rem .25rem;
            border-bottom: 1px solid var(--rrdfx-border);
            background: #fff;
        }
        .rrdfix-docs-tabs button {
            border: 1px solid var(--rrdfx-border);
            background: #fff;
            padding: .35rem .8rem;
            font-size: .82rem; font-weight: 600;
            border-radius: 9999px;
            color: var(--rrdfx-muted);
            cursor: pointer;
            transition: all .15s ease;
        }
        .rrdfix-docs-tabs button.active {
            background: var(--rrdfx-primary);
            border-color: var(--rrdfx-primary);
            color: #fff;
            box-shadow: 0 4px 10px -4px rgba(37,99,235,.45);
        }
        .rrdfix-doc-pane { display: none; }
        .rrdfix-doc-pane.active { display: block; }

        .rrdfix-doc-body {
            max-height: 560px; overflow: auto;
            padding: .25rem .25rem 1rem;
        }
        .rrdfix-doc-body h1, .rrdfix-doc-body h2, .rrdfix-doc-body h3 {
            color: #0f172a; font-weight: 700;
            margin: 1.25rem 0 .5rem; line-height: 1.2;
        }
        .rrdfix-doc-body h2 { font-size: 1.05rem; }
        .rrdfix-doc-body h3 { font-size: .98rem; }
        .rrdfix-doc-body p  { margin: .35rem 0; color: #334155; }
        .rrdfix-doc-body table {
            width: 100%; border-collapse: collapse;
            margin: .5rem 0 .75rem;
            background: #fff; border-radius: .5rem; overflow: hidden;
            border: 1px solid var(--rrdfx-border);
            font-size: .84rem;
        }
        .rrdfix-doc-body th, .rrdfix-doc-body td {
            border-bottom: 1px solid var(--rrdfx-border);
            padding: .45rem .6rem; vertical-align: top;
            text-align: left;
        }
        .rrdfix-doc-body th {
            background: var(--rrdfx-bg-muted);
            font-weight: 700;
            color: #0f172a;
        }
        .rrdfix-doc-body code, .rrdfix-doc-body pre, .rrdfix-doc-body tt {
            font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, monospace;
            background: #0b1220;
            color: #e2e8f0;
            border-radius: .35rem;
            padding: .12rem .4rem;
            font-size: .82rem;
        }
        .rrdfix-doc-body pre {
            padding: .8rem 1rem;
            margin: .5rem 0;
            overflow: auto;
            max-height: 320px;
        }
        .rrdfix-doc-body pre code, .rrdfix-doc-body pre tt { background: transparent; padding: 0; }
        .rrdfix-doc-body ul, .rrdfix-doc-body ol { padding-left: 1.25rem; margin: .35rem 0; }
        .rrdfix-doc-body blockquote {
            border-left: 4px solid var(--rrdfx-primary);
            padding: .25rem .8rem;
            background: #eff6ff;
            color: #1e3a8a;
            margin: .5rem 0;
            border-radius: .35rem;
        }
        .rrdfix-doc-body strong { color: #0f172a; }
        .rrdfix-doc-body em { color: var(--rrdfx-muted); }
        .rrdfix-doc-body hr { border: 0; border-top: 1px dashed var(--rrdfx-border); margin: .8rem 0; }

        @media (max-width: 768px) {
            .rrdfix-flowbite h2.rrdfix-title { font-size: 1.4rem; }
            .rrdfix-progress .rrdfix-metrics { grid-template-columns: 1fr 1fr; }
        }
    </style>

    <h2 class="rrdfix-title">
        <span class="rrdfix-icon-wrap"><i class="fa fa-wrench" aria-hidden="true"></i></span>
        Corrección de RRD
        <span class="rrdfix-version">
            RrdFix <strong>v{{ $plugin_version }}</strong>
        </span>
    </h2>

    @if($flash)
        <div class="alert alert-{{ $flash_type }}">
            <i class="fa fa-{{ $flash_type === 'success' ? 'check-circle' : ($flash_type === 'danger' ? 'exclamation-triangle' : 'info-circle') }}"></i>
            {!! e($flash) !!}
        </div>
    @endif

    {{-- =============================================================
         PASO 1 · Corrección del rango (obligatorio)
         ============================================================= --}}
    <section class="rrdfix-card">
        <header class="rrdfix-card-head">
            <span class="rrdfix-step-badge">1</span>
            <h3>Paso 1 · Corrección del rango <span class="rrdfx-badge warning" style="margin-left:.35rem">Obligatorio</span></h3>
            <span class="rrdfix-muted-lead">Define el periodo exacto y el estado real del dispositivo durante ese tiempo.</span>
        </header>
        <div class="rrdfix-card-body">
            <form id="rrd-fix-form" method="POST" action="{{ route('plugin.update', ['plugin' => 'RrdFix']) }}">
                @csrf
                <input type="hidden" name="settings[rrd_fix_action]" value="run">

                <div class="row">
                    <div class="col-md-3 form-group">
                        <label for="rrd-fix-device">
                            <i class="fa fa-server" style="color:var(--rrdfx-primary)"></i>&nbsp; Dispositivo
                        </label>
                        <select id="rrd-fix-device" name="settings[device]" class="form-control" required @disabled($running)>
                            <option value="">Selecciona un dispositivo</option>
                            @foreach($devices as $device)
                                <option value="{{ $device->hostname }}" @selected($form['device'] === $device->hostname)>
                                    {{ $device->hostname }}{{ $device->sysName ? ' — ' . $device->sysName : '' }}
                                </option>
                            @endforeach
                        </select>
                        <small class="rrdfix-help">Hostname tal como está registrado en LibreNMS.</small>
                    </div>
                    <div class="col-md-3 form-group">
                        <label for="rrd-fix-start">
                            <i class="fa fa-calendar-plus-o" style="color:var(--rrdfx-primary)"></i>&nbsp; Inicio (Lima)
                        </label>
                        <input id="rrd-fix-start" type="datetime-local" name="settings[start]"
                               class="form-control" value="{{ $form['start'] }}" required @disabled($running)>
                        <small class="rrdfix-help">Zona horaria de Lima (America/Lima). Excluido segundos.</small>
                    </div>
                    <div class="col-md-3 form-group">
                        <label for="rrd-fix-end">
                            <i class="fa fa-calendar-check-o" style="color:var(--rrdfx-primary)"></i>&nbsp; Fin (Lima)
                        </label>
                        <input id="rrd-fix-end" type="datetime-local" name="settings[end]"
                               class="form-control" value="{{ $form['end'] }}" required @disabled($running)>
                        <small class="rrdfix-help">Fecha y hora hasta la que repara (incluida).</small>
                    </div>
                    <div class="col-md-3 form-group">
                        <label for="rrd-fix-availability">
                            <i class="fa fa-heartbeat" style="color:var(--rrdfx-primary)"></i>&nbsp; Estado durante el rango
                        </label>
                        <select id="rrd-fix-availability" name="settings[availability]" class="form-control" @disabled($running)>
                            <option value="up" @selected($form['availability'] === 'up')>
                                🟢 No estuvo caído (falsa caída → 100% up)
                            </option>
                            <option value="down" @selected($form['availability'] === 'down')>
                                🔴 Estuvo caído (caída real → 0% up)
                            </option>
                        </select>
                        <small class="rrdfix-help">Define cómo rellenar availability, dBm y throughput.</small>
                    </div>
                </div>

                <div class="rrdfix-check-row">
                    <input id="rrd-fix-no-dbm" type="checkbox" name="settings[no_dbm]" value="1"
                           @checked($form['no_dbm']) @disabled($running)>
                    <div>
                        <label for="rrd-fix-no-dbm" style="margin:0; display:inline">
                            <strong>Omitir dBm SFP</strong>
                        </label>
                        <span class="rrdfix-inline-note">
                            — no fuerza los dBm a -40 durante la caída. Actívalo solo si el problema NO es de enlaces SFP/fibra.
                        </span>
                    </div>
                </div>

                {{-- ======= PASO 2 (anidado) ======= --}}
                <details class="rrdfix-card rrdfix-nested" id="rrd-fix-scan-nan-details"
                         @if($form['scan_nan']) open @endif>
                    <summary style="list-style:none; display:flex; align-items:center; gap:.6rem; background:transparent; border:none; padding:.75rem 1rem; cursor:pointer;">
                        <span class="rrdfix-step-badge secondary">2</span>
                        <span style="font-weight:700; color:#0f172a; font-size:1rem;">
                            Paso 2 (opcional) · Buscar y rellenar NaN en otro rango
                        </span>
                        <span class="rrdfx-badge muted" style="margin-left:.35rem">Recomendado solo si hay huecos intermitentes</span>
                        <span class="rrdfix-acc-chevron"><i class="fa fa-chevron-down" aria-hidden="true"></i></span>
                    </summary>
                    <div class="rrdfix-card-body" id="rrd-fix-scan-nan-fields"
                         style="{{ $form['scan_nan'] ? '' : 'opacity: .55;' }}">
                        <div style="margin-bottom:.75rem; padding:.5rem .75rem; background:#eff6ff; color:#1d4ed8; border:1px solid #bfdbfe; border-radius:.5rem; font-size:.85rem;">
                            <i class="fa fa-info-circle"></i>
                            Actívalo solo si además hubo huecos (valores NaN) esparcidos fuera del periodo del Paso 1
                            (ej: intermitencia durante todo un mes por fallos del poller LibreNMS).
                        </div>
                        <div class="row">
                            <div class="col-md-6 form-group">
                                <label for="rrd-fix-scan-start">Inicio escaneo NaN <span class="rrdfix-inline-note">(opcional)</span></label>
                                <input id="rrd-fix-scan-start" type="datetime-local" name="settings[scan_start]"
                                       class="form-control" value="{{ $form['scan_start'] }}"
                                       @disabled($running || ! $form['scan_nan'])>
                                <small class="rrdfix-help">Fecha desde la que buscar huecos NaN.</small>
                            </div>
                            <div class="col-md-6 form-group">
                                <label for="rrd-fix-scan-end">Fin escaneo NaN <span class="rrdfix-inline-note">(opcional)</span></label>
                                <input id="rrd-fix-scan-end" type="datetime-local" name="settings[scan_end]"
                                       class="form-control" value="{{ $form['scan_end'] }}"
                                       @disabled($running || ! $form['scan_nan'])>
                                <small class="rrdfix-help">Fecha hasta la que buscar huecos NaN.</small>
                            </div>
                        </div>
                        <div style="margin-top:.25rem; padding:.45rem .75rem; background:#f8fafc; color:#475569; border:1px dashed #cbd5e1; border-radius:.5rem; font-size:.82rem;">
                            💡 Si dejas ambas fechas vacías, el escaneo NaN usará **todo el mes** del periodo del Paso 1.
                        </div>
                        <input id="rrd-fix-scan-nan-hidden" type="hidden" name="settings[scan_nan]" value="">
                        <script>
                            (function(){
                                var d = document.getElementById('rrd-fix-scan-nan-details');
                                var fields = document.getElementById('rrd-fix-scan-nan-fields');
                                var hidden = document.getElementById('rrd-fix-scan-nan-hidden');
                                var inp1 = document.getElementById('rrd-fix-scan-start');
                                var inp2 = document.getElementById('rrd-fix-scan-end');
                                function sync(){
                                    if (!d) return;
                                    var on = d.hasAttribute('open');
                                    fields.style.opacity = on ? '1' : '0.55';
                                    hidden.value = on ? '1' : '';
                                    try {
                                        inp1.disabled = !on || {{ $running ? 'true' : 'false' }};
                                        inp2.disabled = !on || {{ $running ? 'true' : 'false' }};
                                    } catch(e) {}
                                    // Move real checkbox behaviour: use hidden value + details open state
                                    // (fallback: also ensure the hidden input name is present and no conflict)
                                }
                                d && d.addEventListener('toggle', sync);
                                sync();
                            })();
                        </script>
                    </div>
                </details>

                <hr style="border-top: 1px dashed var(--rrdfx-border); margin: 1.5rem 0 1.1rem;">

                <div class="row" style="align-items:center;">
                    <div class="col-md-6">
                        <button id="rrd-fix-submit" type="submit"
                                class="btn btn-lg rrdfix-btn rrdfix-btn-danger"
                                @disabled($running)>
                            <i class="fa fa-play"></i>
                            {{ $running ? 'Corrección en curso…' : 'Ejecutar corrección' }}
                        </button>
                        <span style="margin-left:.8rem; color:var(--rrdfx-muted); font-size:.85rem;">
                            El script corre en segundo plano. Los resultados se ven abajo.
                        </span>
                    </div>
                    <div class="col-md-6 text-right">
                        <span class="rrdfx-badge muted" style="font-size:.75rem">
                            <i class="fa fa-lock"></i> Solo usuarios con permiso <code style="background:#f1f5f9; color:#475569; border-radius:.25rem; padding:0 .25rem;">plugin.admin</code>
                        </span>
                    </div>
                </div>
            </form>
        </div>
    </section>

    {{-- =============================================================
         PROGRESO
         ============================================================= --}}
    @php
        $initial = $running ? 'info' : (($log_content && trim($log_content)) ? 'success' : 'default');
    @endphp
    <div id="rrd-fix-progress-row"
         class="rrdfix-progress {{ $initial }}"
         data-state="{{ $initial }}">
        <div class="rrdfix-progress-head">
            <i class="fa {{ $running ? 'fa-cog fa-spin' : (($initial==='success') ? 'fa-check-circle' : 'fa-circle-o') }}" aria-hidden="true"></i>
            <span>Progreso</span>
            <span id="rrd-fix-progress-status"
                  class="rrdfx-badge pull-right {{ $running ? 'info' : (($initial==='success') ? 'success' : 'muted') }}"
                  style="margin-left:auto;">
                @if($running)
                    Iniciando…
                @elseif($initial==='success')
                    Finalizado (última ejecución)
                @else
                    Inactivo
                @endif
            </span>
        </div>
        <div class="rrdfix-progress-body">
            <div class="rrdfix-progress-bar" role="progressbar" aria-valuemin="0" aria-valuemax="100">
                <div id="rrd-fix-progress-bar" style="width:100%"></div>
            </div>
            <div class="rrdfix-metrics">
                <div class="rrdfix-metric">
                    <small><i class="fa fa-clock-o"></i> Tiempo transcurrido</small>
                    <strong id="rrd-fix-elapsed">00:00</strong>
                </div>
                <div class="rrdfix-metric">
                    <small><i class="fa fa-microchip"></i> PID</small>
                    <strong id="rrd-fix-pid">—</strong>
                </div>
                <div class="rrdfix-metric">
                    <small><i class="fa fa-tachometer"></i> CPU</small>
                    <strong id="rrd-fix-cpu">—</strong>
                </div>
                <div class="rrdfix-metric">
                    <small><i class="fa fa-sitemap"></i> RAM</small>
                    <strong id="rrd-fix-mem">—</strong>
                </div>
            </div>
        </div>
    </div>

    {{-- =============================================================
         LOG
         ============================================================= --}}
    <section class="rrdfix-card">
        <header class="rrdfix-card-head">
            <i class="fa fa-file-text-o" style="color:var(--rrdfx-primary)"></i>
            <h3>Log en vivo</h3>
            <span class="rrdfix-muted-lead" style="margin-left:auto">
                <code style="font-size:.78rem; background:#0b1220; color:#a5f3fc; padding:.15rem .45rem; border-radius:.35rem;">
                    {{ $log_path }}
                </code>
                <span id="rrd-fix-last-refresh" class="rrdfix-muted-lead" style="margin-left:.5rem;"></span>
            </span>
        </header>
        <div class="rrdfix-card-body">
            <div id="rrd-fix-log" class="rrdfix-log">@if(trim($log_content)){{ $log_content }}@else<span class="rrdfix-empty">(sin salida todavía)</span>@endif</div>
        </div>
    </section>

    {{-- =============================================================
         DOCUMENTACIÓN (acordeón anidado + tabs internas)
         ============================================================= --}}
    <details class="rrdfix-accordion rrdfix-card" id="rrdfix-docs-root">
        <summary>
            <i class="fa fa-book" style="color:var(--rrdfx-primary)"></i>
            <span>Documentación · Manual del plugin RrdFix</span>
            <span class="rrdfx-badge muted" style="margin-left:.35rem">4 documentos</span>
            <span class="rrdfix-acc-chevron"><i class="fa fa-chevron-down" aria-hidden="true"></i></span>
        </summary>
        <div style="padding:0;">
            <div class="rrdfix-docs-tabs" role="tablist" id="rrdfix-doc-tabs">
                <button type="button" data-doc-tab="guia"        class="active" role="tab">📘 Guía de usuario</button>
                <button type="button" data-doc-tab="faq"         role="tab">❓ FAQ & Problemas</button>
                <button type="button" data-doc-tab="instalacion" role="tab">📦 Instalación &amp; Persistencia</button>
                <button type="button" data-doc-tab="arquitectura" role="tab">🏗 Arquitectura técnica</button>
            </div>
            <div class="rrdfix-acc-body">
                <div class="rrdfix-doc-body">
                    <section class="rrdfix-doc-pane active" data-doc-pane="guia">
                        <h2 style="margin-top:0">{{ $docs['guia']['title'] }}</h2>
                        @include('RrdFix::resources.views.markdown-lines', ['lines' => $docs['guia']['lines']])
                    </section>
                    <section class="rrdfix-doc-pane" data-doc-pane="faq">
                        <h2 style="margin-top:0">{{ $docs['faq']['title'] }}</h2>
                        @include('RrdFix::resources.views.markdown-lines', ['lines' => $docs['faq']['lines']])
                    </section>
                    <section class="rrdfix-doc-pane" data-doc-pane="instalacion">
                        <h2 style="margin-top:0">{{ $docs['instalacion']['title'] }}</h2>
                        @include('RrdFix::resources.views.markdown-lines', ['lines' => $docs['instalacion']['lines']])
                    </section>
                    <section class="rrdfix-doc-pane" data-doc-pane="arquitectura">
                        <h2 style="margin-top:0">{{ $docs['arquitectura']['title'] }}</h2>
                        @include('RrdFix::resources.views.markdown-lines', ['lines' => $docs['arquitectura']['lines']])
                    </section>
                </div>
            </div>
        </div>
    </details>

    <script>
    /* ==========================================================
       RrdFix v1.1 — runtime JS (live log + progress bar + docs tabs)
       ========================================================== */
    (function () {
        var statusUrl  = @json(function_exists('app') && method_exists(app('router'),'has') && app('router')->has('rrdfix.status') ? route('rrdfix.status') : url('rrdfix/status'));
        var running    = @json($running);
        var startedAt  = running ? Date.now() : null;
        var timerId    = null;
        var pollId     = null;

        var $form          = document.getElementById('rrd-fix-form');
        var $progressRow   = document.getElementById('rrd-fix-progress-row');
        var $progressBar   = document.getElementById('rrd-fix-progress-bar');
        var $progressLabel = document.getElementById('rrd-fix-progress-status');
        var $elapsed       = document.getElementById('rrd-fix-elapsed');
        var $pid           = document.getElementById('rrd-fix-pid');
        var $cpu           = document.getElementById('rrd-fix-cpu');
        var $mem           = document.getElementById('rrd-fix-mem');
        var $log           = document.getElementById('rrd-fix-log');
        var $logMeta       = document.getElementById('rrd-fix-last-refresh');
        var $submit        = document.getElementById('rrd-fix-submit');

        /* ----------  Timer  ---------- */
        function pad(n) { return (n < 10 ? '0' : '') + n; }
        function formatElapsed(secs) {
            var h = Math.floor(secs / 3600);
            var m = Math.floor((secs % 3600) / 60);
            var s = Math.floor(secs % 60);
            return (h ? pad(h) + ':' : '') + pad(m) + ':' + pad(s);
        }
        function formatEtime(etime) {
            if (!etime) return null;
            var parts = etime.replace('-', ':').split(':').reverse();
            var s = parseInt(parts[0], 10) || 0;
            var m = parseInt(parts[1], 10) || 0;
            var h = parseInt(parts[2], 10) || 0;
            var d = parseInt(parts[3], 10) || 0;
            return d * 86400 + h * 3600 + m * 60 + s;
        }
        function tickTimer() {
            if (!startedAt) return;
            var secs = (Date.now() - startedAt) / 1000;
            if ($elapsed) $elapsed.textContent = formatElapsed(secs);
        }

        /* ----------  Progress visual state  ---------- */
        function setState(state) {
            // state ∈ { info, success, default }
            if (!$progressRow) return;
            $progressRow.classList.remove('info', 'success', 'default');
            $progressRow.classList.add(state);
            var $head = $progressRow.querySelector('.rrdfix-progress-head i');
            if ($head) {
                $head.classList.remove('fa-cog','fa-spin','fa-check-circle','fa-circle-o');
                if (state === 'info')    { $head.classList.add('fa-cog','fa-spin'); }
                if (state === 'success') { $head.classList.add('fa-check-circle'); }
                if (state === 'default') { $head.classList.add('fa-circle-o'); }
            }
            var $innerBar = document.getElementById('rrd-fix-progress-bar');
            if ($innerBar) {
                $innerBar.classList.remove('progress-bar-success', 'progress-bar-info', 'progress-bar-default');
                $innerBar.style.width = '100%';
                // stripe animation handled via CSS (info state uses animated stripes bg)
            }
            if ($progressLabel) {
                $progressLabel.classList.remove('info','success','muted','warning','danger');
                if (state === 'info') {
                    $progressLabel.classList.add('info');
                    $progressLabel.textContent = 'Procesando…';
                } else if (state === 'success') {
                    $progressLabel.classList.add('success');
                    $progressLabel.textContent = 'Finalizado';
                } else {
                    $progressLabel.classList.add('muted');
                    $progressLabel.textContent = 'Inactivo';
                }
            }
        }

        function setRunning(active, hasLogContent) {
            running = !!active;
            if (running) {
                setState('info');
                if ($submit) {
                    $submit.disabled = true;
                    $submit.innerHTML = '<i class="fa fa-play"></i> Corrección en curso…';
                }
                document.querySelectorAll('#rrd-fix-form input, #rrd-fix-form select, #rrd-fix-form button').forEach(
                    function (el) { el.disabled = true; }
                );
                if (!startedAt) startedAt = Date.now();
                if (!timerId) timerId = setInterval(tickTimer, 1000);
                tickTimer();
            } else {
                setState(hasLogContent ? 'success' : 'default');
                if ($submit) {
                    $submit.disabled = false;
                    $submit.innerHTML = '<i class="fa fa-play"></i> Ejecutar corrección';
                }
                if (timerId) { clearInterval(timerId); timerId = null; }
                document.querySelectorAll('#rrd-fix-form select, #rrd-fix-form button[type="submit"]').forEach(
                    function (el) { el.disabled = false; }
                );
                // Restore Nested Step 2 inputs: only disabled if details is NOT open
                var $d = document.getElementById('rrd-fix-scan-nan-details');
                var $s1 = document.getElementById('rrd-fix-scan-start');
                var $s2 = document.getElementById('rrd-fix-scan-end');
                if ($s1 && $s2 && $d) {
                    var on = $d.hasAttribute('open');
                    $s1.disabled = !on;
                    $s2.disabled = !on;
                }
            }
        }

        /* ----------  Form submit -> start monitoring  ---------- */
        if ($form) {
            $form.addEventListener('submit', function () {
                setTimeout(function () {
                    startedAt = Date.now();
                    setRunning(true, false);
                    startPolling();
                }, 400);
            });
        }

        /* ----------  Polling  ---------- */
        function poll() {
            try {
                var xhr = new XMLHttpRequest();
                xhr.open('GET', statusUrl, true);
                xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest');
                xhr.onload = function () {
                    if (xhr.status !== 200) return;
                    var data;
                    try { data = JSON.parse(xhr.responseText); } catch (e) { return; }

                    var content = (data.log_content && data.log_content.trim()) ? data.log_content : '';
                    setRunning(!!data.running, !!content);

                    if (data.process) {
                        if ($pid) $pid.textContent = data.process.pid || '—';
                        if ($cpu) $cpu.textContent = (data.process.cpu || '—') + '%';
                        if ($mem) $mem.textContent = (data.process.mem || '—') + '%';
                        var secs = formatEtime(data.process.etime);
                        if (typeof secs === 'number' && $elapsed) {
                            $elapsed.textContent = formatElapsed(secs);
                        }
                    } else if (!data.running) {
                        if ($pid) $pid.textContent = '—';
                        if ($cpu) $cpu.textContent = '—';
                        if ($mem) $mem.textContent = '—';
                    }

                    if ($log) {
                        var wasAtBottom =
                            $log.scrollTop + $log.clientHeight >= $log.scrollHeight - 20;
                        if (content) {
                            if ($log.textContent.trim() !== content.trim()) {
                                $log.textContent = content;
                            }
                        } else if (!$log.textContent || $log.textContent === '(sin salida todavía)') {
                            $log.innerHTML = '<span class="rrdfix-empty">(sin salida todavía)</span>';
                        }
                        if (wasAtBottom) $log.scrollTop = $log.scrollHeight;
                    }

                    var now = new Date();
                    if ($logMeta) {
                        $logMeta.textContent = 'actualizado ' +
                            pad(now.getHours()) + ':' + pad(now.getMinutes()) + ':' + pad(now.getSeconds());
                    }

                    if (!data.running && pollId) {
                        clearInterval(pollId); pollId = null;
                    }
                };
                xhr.send();
            } catch (e) { /* ignore */ }
        }
        function startPolling() {
            poll();
            if (pollId) clearInterval(pollId);
            pollId = setInterval(poll, 4000);
        }

        /* ----------  Docs tabs  ---------- */
        (function () {
            var root = document.getElementById('rrdfix-docs-root');
            if (!root) return;
            var btns = root.querySelectorAll('[data-doc-tab]');
            function show(tab) {
                btns.forEach(function (b) {
                    b.classList.toggle('active', b.getAttribute('data-doc-tab') === tab);
                });
                root.querySelectorAll('.rrdfix-doc-pane').forEach(function (p) {
                    p.classList.toggle('active', p.getAttribute('data-doc-pane') === tab);
                });
            }
            btns.forEach(function (b) {
                b.addEventListener('click', function () { show(b.getAttribute('data-doc-tab')); });
            });
        })();

        /* ----------  Kickoff  ---------- */
        startPolling();
    })();
    </script>
</div>
