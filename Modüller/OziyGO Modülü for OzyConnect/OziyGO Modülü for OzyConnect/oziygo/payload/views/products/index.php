<?php
$filters        = $data['filters'] ?? [];
$categories     = $data['categories'] ?? [];
$brands         = $data['brands'] ?? [];
$marketplaces   = $data['marketplaces'] ?? [];
$mpProductCounts = $data['mpProductCounts'] ?? [];
$totalProducts  = $data['totalProducts'] ?? 0;
$totalMatched   = $data['totalMatched'] ?? 0;
$totalUnmatched = $data['totalUnmatched'] ?? 0;
$totalSyncIssues = $data['totalSyncIssues'] ?? 0;
$currentMp      = $filters['marketplace_id'] ?? '';
$csrf           = $data['_csrf'] ?? '';

$mpColors = [
    'trendyol'=>'#f27a1a','hepsiburada'=>'#ff6000','n11'=>'#7b2d96',
    'amazon'=>'#ff9900','pazarama'=>'#00a8e1','pttavm'=>'#ffc107','ciceksepeti'=>'#e91e63'
];
?>
<style>
/* ═══ SHARED COMPONENTS (Orders ile aynı) ═══ */
.o-toolbar{display:flex;align-items:center;gap:12px;margin-bottom:16px;flex-wrap:wrap}
.o-search{display:flex;align-items:center;gap:8px;background:var(--bg-card);border:1px solid var(--border);border-radius:10px;padding:8px 14px;width:260px;transition:border-color .15s}
.o-search:focus-within{border-color:var(--primary)}
.o-search input{border:none;outline:none;background:transparent;font-size:13px;width:100%;color:var(--text-primary);font-family:inherit}
.o-search input::placeholder{color:var(--text-muted)}
.o-sel{padding:8px 12px;border:1px solid var(--border);border-radius:10px;font-size:13px;background:var(--bg-card);color:var(--text-primary);cursor:pointer;font-family:inherit;font-weight:500}
.o-sel:focus{border-color:var(--primary);outline:none}
.o-actions{margin-left:auto;display:flex;gap:8px}
.o-btn{display:inline-flex;align-items:center;gap:6px;padding:8px 16px;border-radius:10px;font-size:13px;font-weight:700;border:none;cursor:pointer;transition:all .15s;font-family:inherit}
.o-btn-primary{background:var(--primary);color:#fff}.o-btn-primary:hover{opacity:.9}
.o-btn-outline{background:transparent;border:1px solid var(--border);color:var(--text-primary)}.o-btn-outline:hover{border-color:var(--primary);color:var(--primary)}
.o-btn-success{background:#10b981;color:#fff}.o-btn-success:hover{background:#059669}
.o-btn-danger{background:#ef4444;color:#fff}.o-btn-danger:hover{background:#dc2626}
.o-btn-warning{background:#f59e0b;color:#fff}.o-btn-warning:hover{background:#d97706}
.o-btn-sm{padding:6px 12px;font-size:12px;border-radius:8px}

/* Table card */
.o-card{background:var(--bg-card);border:1px solid var(--border);border-radius:14px;overflow:hidden;box-shadow:var(--shadow-card)}
.o-table{width:100%;border-collapse:collapse}
.o-table thead th{padding:12px 16px;font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.5px;color:var(--text-muted);background:var(--bg-input);border-bottom:1px solid var(--border);white-space:nowrap;text-align:left}
.o-table tbody tr{border-bottom:1px solid var(--border);transition:background .1s}
.o-table tbody tr:last-child{border-bottom:none}
.o-table tbody tr:hover{background:var(--bg-sidebar-hover)}
.o-table td{padding:14px 16px;font-size:13px;vertical-align:middle}

/* Row actions */
.o-row-actions{display:flex;gap:4px;flex-wrap:wrap}
.o-ra{display:inline-flex;align-items:center;gap:4px;padding:5px 10px;border-radius:8px;font-size:11px;font-weight:700;text-decoration:none;border:1px solid var(--border);color:var(--text-secondary);background:transparent;cursor:pointer;transition:all .12s;white-space:nowrap;font-family:inherit}
.o-ra:hover{border-color:var(--primary);color:var(--primary);background:var(--bg-sidebar-hover)}
.o-ra-sync{color:var(--primary);border-color:rgba(25,103,242,.3)}
.o-ra-sync:hover{background:var(--primary-bg)}

/* Badges */
.o-badge{display:inline-flex;align-items:center;padding:3px 10px;border-radius:20px;font-size:11px;font-weight:700;white-space:nowrap}
.o-empty{text-align:center;padding:60px 20px;color:var(--text-muted)}
.o-empty i{display:block;margin-bottom:8px}

/* Bulk bar */
.o-bulk{display:none;align-items:center;gap:12px;padding:10px 16px;background:#eff6ff;border-bottom:1px solid #bfdbfe;font-size:13px;font-weight:700;color:#1d4ed8}
.o-bulk.show{display:flex}
.o-bulk-actions{display:flex;gap:6px;flex-wrap:wrap;flex:1}

/* ═══ DataTables Overrides ═══ */
.dataTables_wrapper{background:transparent!important}
.dataTables_wrapper .dataTables_processing{background:var(--bg-card)!important;color:var(--text-primary)!important;border:1px solid var(--border)!important;border-radius:10px!important;padding:12px 24px!important;box-shadow:var(--shadow-card)!important;font-size:13px!important;z-index:10!important}
table.dataTable{border-collapse:collapse!important}
table.dataTable thead th{border-bottom:1px solid var(--border)!important;cursor:pointer}
table.dataTable thead .sorting:after,table.dataTable thead .sorting_asc:after,table.dataTable thead .sorting_desc:after{opacity:.4;font-size:10px}
table.dataTable thead .sorting_asc:after,table.dataTable thead .sorting_desc:after{opacity:.9;color:var(--primary)}
table.dataTable tbody tr{background:transparent!important}
table.dataTable.no-footer{border-bottom:none!important}
table.dataTable td.dataTables_empty{padding:60px 20px!important;text-align:center;color:var(--text-muted);font-size:14px}
.dt-footer{display:flex;align-items:center;justify-content:space-between;padding:12px 16px;border-top:1px solid var(--border);flex-wrap:wrap;gap:8px}
.dataTables_info{font-size:12px;color:var(--text-muted);padding:0!important}
.dataTables_paginate{padding:0!important}
.dataTables_paginate .paginate_button,
.paginate_button{min-width:32px;height:32px;display:inline-flex!important;align-items:center;justify-content:center;border-radius:8px!important;font-size:12px!important;font-weight:700;color:var(--text-secondary)!important;background:var(--bg-card)!important;border:1px solid var(--border)!important;margin:0 2px!important;padding:0 8px!important;transition:all .12s;cursor:pointer}
.dataTables_paginate .paginate_button:hover,
.paginate_button:hover{border-color:var(--primary)!important;color:var(--primary)!important;background:var(--bg-sidebar-hover)!important}
.dataTables_paginate .paginate_button.current,
.paginate_button.current{background:var(--primary)!important;color:#fff!important;border-color:var(--primary)!important}
.dataTables_paginate .paginate_button.disabled,
.paginate_button.disabled{opacity:.3!important;pointer-events:none}
.dataTables_length,.dataTables_filter{display:none!important}

/* ═══ PRODUCTS-SPECIFIC ═══ */
/* Inline editable cells */
.inline-edit{position:relative;cursor:pointer;padding:4px 8px;border-radius:6px;transition:all .12s}
.inline-edit:hover{background:var(--primary-bg)}
.inline-edit .edit-icon{display:none;position:absolute;right:0;top:50%;transform:translateY(-50%);font-size:9px;color:var(--primary);opacity:.7}
.inline-edit:hover .edit-icon{display:block}
.inline-input{width:90px;padding:6px 8px;border:2px solid var(--primary);border-radius:6px;font-size:13px;font-family:inherit;background:var(--bg-card);color:var(--text-primary);text-align:right;font-weight:700}
.inline-input:focus{outline:none;box-shadow:0 0 0 3px var(--primary-bg)}

/* Marketplace color-strip bar in table */
.mp-strip{display:inline-flex;gap:2px;align-items:center;justify-content:center;padding:2px 4px;border-radius:6px;background:var(--bg-input,#f1f5f9)}
.mp-seg{width:7px;height:20px;border-radius:3px;cursor:default;transition:all .18s;position:relative}
.mp-seg:first-child{border-radius:3px 2px 2px 3px}
.mp-seg:last-child{border-radius:2px 3px 3px 2px}
.mp-seg:hover{transform:scaleY(1.3);z-index:2;box-shadow:0 0 6px rgba(0,0,0,.2)}
.mp-seg.active{opacity:1}
.mp-seg.inactive{opacity:.45;background-image:repeating-linear-gradient(135deg,transparent,transparent 2px,rgba(255,255,255,.4) 2px,rgba(255,255,255,.4) 4px)}
.mp-seg.none{opacity:.12}
.mp-seg.error{animation:pulse-dot 1.2s infinite}
/* Marketplace tooltip popover */
.mp-strip-wrap{position:relative;display:inline-flex;align-items:center;gap:6px}
.mp-strip-wrap .mp-count{font-size:11px;font-weight:600;color:var(--text-secondary);white-space:nowrap}
/* Floating popover */
.mp-popover{position:fixed;z-index:9999;background:var(--bg-card,#fff);border:1px solid var(--border,#e2e8f0);border-radius:10px;box-shadow:0 8px 24px rgba(0,0,0,.15);padding:8px 6px;display:none;min-width:180px;pointer-events:none}
.mp-popover.show{display:block}
.mp-pop-row{display:flex;align-items:center;gap:8px;padding:4px 8px;border-radius:6px;font-size:12px;white-space:nowrap}
.mp-pop-row+.mp-pop-row{margin-top:2px}
.mp-pop-row img{width:20px;height:20px;object-fit:contain;border-radius:4px}
.mp-pop-row .mp-pop-name{flex:1;font-weight:500;color:var(--text-primary)}
.mp-pop-row .mp-pop-status{font-size:10px;font-weight:600;padding:2px 7px;border-radius:10px}
.mp-pop-row .mp-pop-status.s-active{background:#dcfce7;color:#15803d}
.mp-pop-row .mp-pop-status.s-inactive{background:#fee2e2;color:#dc2626}
.mp-pop-row .mp-pop-status.s-error{background:#fef3c7;color:#d97706}
.mp-pop-row .mp-pop-status.s-none{background:var(--bg-input,#f1f5f9);color:var(--text-muted,#94a3b8)}
.mp-pop-row.is-none{opacity:.5}

/* Image in table */
.p-thumb{width:44px;height:44px;border-radius:8px;object-fit:cover;border:1px solid var(--border);cursor:pointer;transition:all .15s;background:var(--bg-input)}
.p-thumb:hover{transform:scale(1.15);box-shadow:var(--shadow-md);border-color:var(--primary);z-index:10;position:relative}
.p-thumb-empty{width:44px;height:44px;border-radius:8px;display:flex;align-items:center;justify-content:center;background:var(--bg-input);border:1px dashed var(--border);color:var(--text-muted);font-size:16px}

/* Progress modal */
.sync-progress{padding:8px 0}
.sync-line{display:flex;align-items:center;gap:10px;padding:8px 0;border-bottom:1px solid var(--border-light)}
.sync-line:last-child{border-bottom:none}
.sync-line .mp-name{font-weight:700;font-size:12px;width:110px;flex-shrink:0}
.sync-line .sync-status{font-size:11px;font-weight:700;display:flex;align-items:center;gap:4px}
.sync-line .sync-status.ok{color:var(--success)}
.sync-line .sync-status.fail{color:var(--danger)}
.sync-line .sync-status.wait{color:var(--warning)}
@keyframes spin{to{transform:rotate(360deg)}}
@keyframes pulse-dot{0%,100%{opacity:1}50%{opacity:.4}}

/* ═══ TILE GRID ═══ */
.mp-grid{display:flex;gap:10px;margin-bottom:20px;overflow-x:auto;overflow-y:hidden;padding-bottom:2px}.mp-grid>.mp-tile{flex:1 1 0;min-width:110px}
.mp-tile{position:relative;display:flex;flex-direction:column;align-items:center;justify-content:center;gap:6px;padding:16px 8px 14px;border-radius:12px;cursor:pointer;border:1.5px solid var(--border);background:var(--bg-card);transition:all .18s ease;min-height:80px;overflow:hidden}
.mp-tile:hover{transform:translateY(-2px);box-shadow:0 6px 20px rgba(0,0,0,.1);border-color:var(--mp-color,var(--primary));background:var(--mp-bg,var(--bg-card))}
.mp-tile:active{transform:translateY(0);box-shadow:0 2px 6px rgba(0,0,0,.06)}
.mp-tile-logo{height:24px;width:auto;max-width:90px;object-fit:contain;opacity:.8;transition:opacity .15s}
.mp-tile:hover .mp-tile-logo{opacity:1}
.mp-tile-count{display:inline-flex;align-items:center;justify-content:center;min-width:22px;height:20px;border-radius:10px;font-size:11px;font-weight:600;color:#fff;background:var(--mp-color,var(--primary));padding:0 6px;line-height:1}
.mp-tile-count.zero{background:var(--border);color:var(--text-muted);font-weight:600}
.mp-tile-inactive{opacity:.3;pointer-events:none;filter:grayscale(.7)}
.mp-tile-inactive .mp-tile-soon{position:absolute;bottom:4px;right:4px;font-size:8px;background:#ef4444;color:#fff;padding:1px 5px;border-radius:4px;font-weight:700}

/* ═══ OVERLAY ═══ */
.mp-overlay{position:fixed;top:0;left:0;right:0;bottom:0;z-index:9999;display:none;flex-direction:column;background:rgba(0,0,0,.45);backdrop-filter:blur(4px);animation:mpOverlayIn .2s ease}
.mp-overlay.active{display:flex}
@keyframes mpOverlayIn{from{opacity:0}to{opacity:1}}
.mp-window{position:absolute;top:20px;left:20px;right:20px;bottom:20px;background:var(--bg-page,#f8fafc);border-radius:16px;box-shadow:0 25px 60px rgba(0,0,0,.3);display:flex;flex-direction:column;overflow:hidden;animation:mpWindowIn .25s ease}
@keyframes mpWindowIn{from{transform:scale(.96) translateY(10px);opacity:0}to{transform:scale(1) translateY(0);opacity:1}}
.mp-win-header{display:flex;align-items:center;gap:16px;padding:16px 24px;border-bottom:2px solid var(--mp-color,var(--border));background:var(--bg-card);flex-shrink:0}
.mp-win-logo{height:40px;width:auto;max-width:200px;object-fit:contain}
.mp-win-title{font-size:18px;font-weight:600;color:var(--text-primary);flex:1}
.mp-win-close{width:40px;height:40px;border-radius:10px;border:1px solid var(--border);background:transparent;cursor:pointer;display:flex;align-items:center;justify-content:center;color:var(--text-muted);transition:all .15s}
.mp-win-close:hover{background:#fef2f2;color:#ef4444;border-color:#ef4444}
.mp-win-toolbar{display:flex;align-items:center;gap:10px;padding:12px 24px;background:var(--bg-card);border-bottom:1px solid var(--border);flex-shrink:0;flex-wrap:wrap}
.mp-win-body{flex:1;overflow-y:auto;padding:16px 24px}

/* ═══ MP TABLE (overlay) ═══ */
.mp-table{width:100%;border-collapse:collapse;background:var(--bg-card);border:1px solid var(--border);border-radius:12px;overflow:hidden}
.mp-table th{padding:10px 12px;font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:var(--text-muted);background:var(--bg-input);border-bottom:1px solid var(--border);text-align:left}
.mp-table td{padding:8px 12px;font-size:13px;border-bottom:1px solid var(--border);vertical-align:middle}
.mp-table tr:hover{background:rgba(25,103,242,.03)}
.mp-status{display:inline-flex;align-items:center;gap:4px;padding:3px 10px;border-radius:20px;font-size:10px;font-weight:700}
.mp-status.matched{background:#dcfce7;color:#16a34a}
.mp-status.unmatched{background:#fef3c7;color:#d97706}
.mp-status.error{background:#fee2e2;color:#dc2626}
.mp-pagination{display:flex;align-items:center;justify-content:center;gap:6px;padding:16px 0}
.mp-pagination button{min-width:32px;height:32px;display:inline-flex;align-items:center;justify-content:center;border-radius:8px;font-size:12px;font-weight:700;color:var(--text-secondary);background:var(--bg-card);border:1px solid var(--border);cursor:pointer;padding:0 8px;transition:all .12s}
.mp-pagination button:hover{border-color:var(--primary);color:var(--primary)}
.mp-pagination button.active{background:var(--primary);color:#fff;border-color:var(--primary)}
.mp-pagination button:disabled{opacity:.3;pointer-events:none}

@media(max-width:1200px){.mp-grid{grid-template-columns:repeat(4,1fr)}}
@media(max-width:768px){.mp-grid{grid-template-columns:repeat(2,1fr);gap:8px}.mp-tile{padding:12px 6px 10px;min-height:60px}.mp-tile-logo{height:20px;max-width:70px}.mp-window{top:10px;left:10px;right:10px;bottom:10px}.mp-win-header{padding:12px 16px;gap:10px}.mp-win-toolbar{padding:10px 16px}}

/* ═══ Sync Box ═══ */
.p-sync-box{display:none;background:var(--bg-card);border:1px solid var(--border);border-radius:12px;padding:20px;margin-bottom:16px;box-shadow:var(--shadow-card)}
.p-sync-box.show{display:block}
.p-sync-bar{height:6px;background:#e2e8f0;border-radius:3px;margin:12px 0;overflow:hidden}
.p-sync-bar-fill{height:100%;border-radius:3px;transition:width .4s;width:0}

/* ═══ Confirm Modal ═══ */
.confirm-overlay{display:none;position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:20000;align-items:center;justify-content:center;backdrop-filter:blur(4px)}
.confirm-overlay.show{display:flex}
.confirm-box{background:var(--bg-card,#fff);border-radius:16px;width:95%;max-width:400px;padding:28px;box-shadow:0 20px 60px rgba(0,0,0,.25);text-align:center}
.confirm-icon{width:56px;height:56px;border-radius:50%;display:flex;align-items:center;justify-content:center;margin:0 auto 16px;font-size:24px}
.confirm-icon.warn{background:#fef3c7;color:#f59e0b}
.confirm-icon.danger{background:#fee2e2;color:#ef4444}
.confirm-icon.info{background:#eff6ff;color:#1967F2}
.confirm-title{font-size:16px;font-weight:700;margin:0 0 8px;color:var(--text-primary,#1e293b)}
.confirm-msg{font-size:13px;color:var(--text-muted,#64748b);margin:0 0 24px;line-height:1.5}
.confirm-btns{display:flex;gap:10px;justify-content:center}
.confirm-btns button{padding:10px 24px;border-radius:10px;font-size:13px;font-weight:700;cursor:pointer;border:none;font-family:inherit;transition:all .12s}
.confirm-btn-cancel{background:var(--bg-input,#f1f5f9);color:var(--text-secondary,#475569)}
.confirm-btn-cancel:hover{background:#e2e8f0}
.confirm-btn-ok{background:#ef4444;color:#fff}
.confirm-btn-ok:hover{background:#dc2626}
.confirm-btn-ok.primary{background:var(--primary,#1967F2)}
.confirm-btn-ok.primary:hover{background:#0F52D4}
.confirm-btn-ok.success{background:#10b981}
.confirm-btn-ok.success:hover{background:#059669}

/* ═══ PRODUCT FORM OVERLAY ═══ */
.pfo-overlay{position:fixed;top:0;left:0;right:0;bottom:0;z-index:10000;display:none;background:rgba(0,0,0,.5);backdrop-filter:blur(4px);animation:mpOverlayIn .2s ease}
.pfo-overlay.active{display:flex}
.pfo-window{position:absolute;top:16px;left:16px;right:16px;bottom:16px;background:var(--bg-page,#f8fafc);border-radius:16px;box-shadow:0 25px 60px rgba(0,0,0,.3);display:flex;flex-direction:column;overflow:hidden;animation:mpWindowIn .25s ease}
.pfo-header{display:flex;align-items:center;gap:16px;padding:14px 24px;border-bottom:2px solid var(--primary);background:var(--bg-card);flex-shrink:0}
.pfo-header h2{font-size:17px;font-weight:700;color:var(--text-primary);margin:0;flex:1;display:flex;align-items:center;gap:8px}
.pfo-header h2 [data-lucide]{width:20px;height:20px;color:var(--primary)}
.pfo-close{width:38px;height:38px;border-radius:10px;border:1px solid var(--border);background:transparent;cursor:pointer;display:flex;align-items:center;justify-content:center;color:var(--text-muted);transition:all .15s}
.pfo-close:hover{background:#fef2f2;color:#ef4444;border-color:#ef4444}

/* Form Tabs */
.pfo-tabs{display:flex;gap:0;padding:0 24px;background:var(--bg-card);border-bottom:1px solid var(--border);flex-shrink:0;overflow-x:auto}
.pfo-tab{padding:11px 20px;font-size:12px;font-weight:700;color:var(--text-muted);cursor:pointer;border-bottom:2.5px solid transparent;transition:all .15s;white-space:nowrap;display:flex;align-items:center;gap:6px}
.pfo-tab [data-lucide]{width:14px;height:14px}
.pfo-tab:hover{color:var(--text-primary);background:rgba(25,103,242,.04)}
.pfo-tab.active{color:var(--primary);border-bottom-color:var(--primary)}
.pfo-body{flex:1;overflow-y:auto;padding:20px 24px}
.pfo-panel{display:none}
.pfo-panel.active{display:block}

/* Split Layout: Form + Sidebar */
.pfo-main{display:flex;flex:1;overflow:hidden;min-height:0}
.pfo-content{flex:1;display:flex;flex-direction:column;overflow:hidden;min-width:0}
.pfo-sidebar{width:380px;flex-shrink:0;border-left:1px solid var(--border);background:var(--bg-page,#f8fafc);display:none;flex-direction:column;overflow:hidden}
.pfo-sidebar.active{display:flex}
.pfo-sidebar-head{padding:12px 16px;font-size:12px;font-weight:700;color:var(--text-muted);text-transform:uppercase;letter-spacing:.5px;display:flex;align-items:center;justify-content:space-between;gap:8px;border-bottom:1px solid var(--border);background:var(--bg-card);flex-shrink:0}
.pfo-sidebar-head [data-lucide]{width:14px;height:14px}
.pfo-sidebar-body{flex:1;overflow-y:auto;padding:12px}

/* Form Sections */
.pfo-sec{background:var(--bg-card);border:1px solid var(--border);border-radius:12px;overflow:hidden;margin-bottom:16px}
.pfo-sec:last-child{margin-bottom:0}
.pfo-sec-head{padding:12px 16px;font-size:11px;font-weight:700;color:var(--text-muted);text-transform:uppercase;letter-spacing:.5px;display:flex;align-items:center;gap:6px;border-bottom:1px solid var(--border);background:var(--bg-sidebar)}
.pfo-sec-head [data-lucide]{width:14px;height:14px}
.pfo-sec-body{padding:16px}
.pfo-row{display:grid;gap:12px;margin-bottom:12px}
.pfo-row:last-child{margin-bottom:0}
.pfo-r2{grid-template-columns:1fr 1fr}
.pfo-r3{grid-template-columns:1fr 1fr 1fr}
.pfo-r4{grid-template-columns:1fr 1fr 1fr 1fr}
.pfo-f{display:flex;flex-direction:column;gap:3px}
.pfo-f label{font-size:11px;font-weight:700;color:var(--text-secondary)}
.pfo-f input,.pfo-f select,.pfo-f textarea{padding:7px 10px;border:1px solid var(--border);border-radius:6px;background:var(--bg-sidebar);color:var(--text-primary);font-size:13px;font-family:inherit;transition:border-color .15s}
.pfo-f input:focus,.pfo-f select:focus,.pfo-f textarea:focus{outline:none;border-color:var(--primary);box-shadow:0 0 0 2px rgba(25,103,242,.08)}
.pfo-f textarea{min-height:70px;resize:vertical}

/* Toggle */
.pfo-toggle{position:relative;width:40px;height:22px;flex-shrink:0;display:inline-block}
.pfo-toggle input{opacity:0;width:0;height:0}
.pfo-toggle-slider{position:absolute;inset:0;background:#cbd5e1;border-radius:22px;cursor:pointer;transition:all .25s}
.pfo-toggle-slider::before{content:'';position:absolute;width:16px;height:16px;left:3px;bottom:3px;background:#fff;border-radius:50%;transition:all .25s;box-shadow:0 1px 3px rgba(0,0,0,.15)}
.pfo-toggle input:checked+.pfo-toggle-slider{background:#10b981}
.pfo-toggle input:checked+.pfo-toggle-slider::before{transform:translateX(18px)}

/* Images Gallery */
.pfo-imgs{display:grid;grid-template-columns:repeat(auto-fill,minmax(100px,1fr));gap:10px}
.pfo-img{aspect-ratio:1;border-radius:10px;border:2px solid var(--border);position:relative;overflow:hidden;cursor:pointer;transition:all .15s;background:var(--bg-sidebar)}
.pfo-img:hover{border-color:var(--primary);transform:translateY(-1px);box-shadow:0 4px 12px rgba(0,0,0,.1)}
.pfo-img img{width:100%;height:100%;object-fit:cover}
.pfo-img .badge{position:absolute;top:4px;left:4px;background:var(--primary);color:#fff;font-size:8px;font-weight:700;padding:2px 6px;border-radius:4px;z-index:1}
.pfo-img .acts{position:absolute;inset:0;background:rgba(0,0,0,.5);display:flex;align-items:center;justify-content:center;gap:6px;opacity:0;transition:opacity .15s}
.pfo-img:hover .acts{opacity:1}
.pfo-img .acts button{width:28px;height:28px;border-radius:6px;border:none;cursor:pointer;color:#fff;display:flex;align-items:center;justify-content:center;font-size:13px;transition:transform .1s}
.pfo-img .acts button:hover{transform:scale(1.1)}
.pfo-img .acts .del{background:rgba(239,68,68,.9)}
.pfo-img .acts .star{background:rgba(25,103,242,.9)}
.pfo-img-add{aspect-ratio:1;border-radius:10px;border:2px dashed var(--border);display:flex;flex-direction:column;align-items:center;justify-content:center;gap:4px;cursor:pointer;color:var(--text-muted);font-size:11px;transition:all .15s;background:var(--bg-sidebar)}
.pfo-img-add:hover{border-color:var(--primary);color:var(--primary);background:rgba(25,103,242,.04)}
.pfo-img-add [data-lucide]{width:20px;height:20px}
.pfo-drop{border:2px dashed var(--border);border-radius:10px;padding:24px;text-align:center;cursor:pointer;transition:all .15s;margin-top:12px;font-size:12px;color:var(--text-muted);background:var(--bg-sidebar)}
.pfo-drop:hover,.pfo-drop.dragover{border-color:var(--primary);color:var(--primary);background:rgba(25,103,242,.04)}
.pfo-url-row{margin-top:10px;display:flex;gap:8px}
.pfo-url-row input{flex:1;padding:7px 10px;border:1px solid var(--border);border-radius:6px;font-size:12px;background:var(--bg-sidebar);color:var(--text-primary)}

/* Quill Editor */
.pfo-editor-wrap{border:1px solid var(--border);border-radius:8px;overflow:hidden;background:var(--bg-card)}
.pfo-editor-wrap .ql-toolbar{border:none;border-bottom:1px solid var(--border);background:var(--bg-sidebar)}
.pfo-editor-wrap .ql-container{border:none;font-size:13px;font-family:inherit;min-height:150px}
.pfo-editor-wrap .ql-editor{min-height:150px;color:var(--text-primary)}
.pfo-editor-wrap .ql-editor.ql-blank::before{color:var(--text-muted);font-style:normal}

/* Variants */
.pfo-vr-row{display:grid;grid-template-columns:2fr 1.5fr 1.5fr 1fr 1fr 40px;gap:8px;align-items:end;padding:8px 0;border-bottom:1px solid var(--border)}
.pfo-vr-row:last-child{border-bottom:none}
.pfo-vr-header{font-size:10px;font-weight:700;color:var(--text-muted);text-transform:uppercase;letter-spacing:.5px;padding-bottom:4px;border-bottom:1px solid var(--border)}
.pfo-vr-row input{padding:6px 8px;border:1px solid var(--border);border-radius:5px;background:var(--bg-sidebar);color:var(--text-primary);font-size:12px;font-family:inherit;width:100%}
.pfo-vr-row input:focus{outline:none;border-color:var(--primary)}
.pfo-vr-del{width:28px;height:28px;border-radius:5px;border:none;background:none;color:var(--text-muted);cursor:pointer;display:flex;align-items:center;justify-content:center;transition:all .1s}
.pfo-vr-del:hover{background:rgba(239,68,68,.1);color:var(--danger)}
.pfo-vr-add{display:inline-flex;align-items:center;gap:4px;margin-top:8px;padding:6px 12px;border:1px dashed var(--border);border-radius:6px;background:none;color:var(--text-muted);font-size:11px;cursor:pointer;transition:all .15s}
.pfo-vr-add:hover{border-color:var(--primary);color:var(--primary)}

/* Footer */
.pfo-footer{display:flex;align-items:center;justify-content:space-between;padding:12px 24px;border-top:1px solid var(--border);background:var(--bg-card);flex-shrink:0}

/* Description split layout */
.pfo-desc-grid{display:grid;grid-template-columns:1fr 1fr;gap:20px}

/* SEO fields */
.pfo-seo-preview{background:var(--bg-sidebar);border:1px solid var(--border);border-radius:8px;padding:12px 16px;margin-top:8px}
.pfo-seo-preview .seo-title{font-size:16px;color:#1a0dab;font-weight:400;margin:0 0 2px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.pfo-seo-preview .seo-url{font-size:12px;color:#006621;margin:0 0 2px}
.pfo-seo-preview .seo-desc{font-size:12px;color:#545454;margin:0;display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:hidden}

/* Marketplace Cards */
.pfo-mp-card{border:1px solid var(--border);border-radius:10px;overflow:hidden;margin-bottom:12px;transition:all .15s;background:var(--bg-card)}
.pfo-mp-card:last-child{margin-bottom:0}
.pfo-mp-card.enabled{border-color:rgba(16,185,129,.4);box-shadow:0 0 0 1px rgba(16,185,129,.08)}
.pfo-mp-card-head{display:flex;align-items:center;gap:10px;padding:10px 12px;cursor:pointer;transition:background .1s}
.pfo-mp-card-head:hover{background:rgba(25,103,242,.02)}
.pfo-mp-icon{width:40px;height:40px;border-radius:8px;display:flex;align-items:center;justify-content:center;font-size:18px;flex-shrink:0}
.pfo-mp-info{flex:1;min-width:0}
.pfo-mp-name{font-size:13px;font-weight:700;color:var(--text-primary)}
.pfo-mp-status{font-size:11px;color:var(--text-muted);margin-top:1px;display:flex;align-items:center;gap:6px;flex-wrap:wrap}
.pfo-mp-badge{display:inline-flex;align-items:center;gap:3px;padding:1px 7px;border-radius:4px;font-size:10px;font-weight:600;white-space:nowrap}
.pfo-mp-badge.ok{background:#d1fae5;color:#059669}
.pfo-mp-badge.warn{background:#fef3c7;color:#d97706}
.pfo-mp-badge.err{background:#fee2e2;color:#dc2626}
.pfo-mp-badge.info{background:#e0e7ff;color:#0F52D4}
.pfo-mp-body{display:none;padding:0 12px 12px;border-top:1px solid var(--border)}
.pfo-mp-card.enabled .pfo-mp-body{display:block}
.pfo-mp-grid{display:grid;grid-template-columns:1fr 1fr;gap:8px;margin-top:10px}
.pfo-mp-field{display:flex;flex-direction:column;gap:2px}
.pfo-mp-field label{font-size:10px;font-weight:700;color:var(--text-muted);text-transform:uppercase;letter-spacing:.3px}
.pfo-mp-field input,.pfo-mp-field select{padding:6px 8px;border:1px solid var(--border);border-radius:5px;font-size:12px;background:var(--bg-sidebar);color:var(--text-primary);font-family:inherit}
.pfo-mp-field input:focus,.pfo-mp-field select:focus{outline:none;border-color:var(--primary);box-shadow:0 0 0 2px rgba(25,103,242,.08)}
.pfo-mp-preview{display:grid;grid-template-columns:1fr 1fr;gap:10px;margin-top:10px;padding:10px 12px;border-radius:6px;background:var(--bg-sidebar);border:1px solid var(--border)}
.pfo-mp-prev-item{display:flex;flex-direction:column;gap:1px}
.pfo-mp-prev-label{font-size:10px;color:var(--text-muted);font-weight:600}
.pfo-mp-prev-val{font-size:14px;font-weight:700;color:var(--text-primary)}
.pfo-mp-prev-val.price{color:var(--primary)}
.pfo-mp-actions{display:flex;gap:6px;margin-top:10px;flex-wrap:wrap}
.pfo-mp-actions button{font-size:11px;padding:5px 12px;border-radius:5px;border:1px solid var(--border);background:var(--bg-card);color:var(--text-primary);cursor:pointer;display:flex;align-items:center;gap:4px;transition:all .15s;font-family:inherit;font-weight:600}
.pfo-mp-actions button:hover{border-color:var(--primary);color:var(--primary);background:rgba(25,103,242,.04)}
.pfo-mp-actions button:disabled{opacity:.5;cursor:not-allowed}
.pfo-mp-actions button.btn-publish{background:var(--primary);color:#fff;border-color:var(--primary)}
.pfo-mp-actions button.btn-publish:hover{background:#0F52D4}
.pfo-mp-sync-info{margin-top:8px;font-size:11px;color:var(--text-muted);display:flex;align-items:center;gap:8px;flex-wrap:wrap}
.pfo-mp-sync-err{margin-top:6px;padding:6px 10px;border-radius:5px;background:#fef2f2;border:1px solid #fecaca;font-size:11px;color:#dc2626}
.pfo-mp-match-warn{margin-top:8px;padding:8px 12px;border-radius:6px;background:#fffbeb;border:1px solid #fde68a;font-size:11px;color:#92400e;display:flex;align-items:flex-start;gap:6px;line-height:1.5}

@media(max-width:1200px){.pfo-sidebar{width:340px}}
@media(max-width:900px){.pfo-sidebar{position:absolute;right:0;top:0;bottom:0;width:380px;z-index:10;box-shadow:-4px 0 20px rgba(0,0,0,.15)}.pfo-mp-grid{grid-template-columns:1fr}}
@media(max-width:600px){.pfo-sidebar{width:100%}.pfo-mp-grid{grid-template-columns:1fr}.pfo-mp-preview{grid-template-columns:1fr}}

@media(max-width:1000px){.pfo-r3,.pfo-r4{grid-template-columns:1fr 1fr}.pfo-desc-grid{grid-template-columns:1fr}.pfo-imgs{grid-template-columns:repeat(auto-fill,minmax(80px,1fr))}}
@media(max-width:600px){.pfo-r2,.pfo-r3,.pfo-r4{grid-template-columns:1fr}.pfo-tabs{padding:0 12px}.pfo-tab{padding:10px 14px;font-size:11px}.pfo-body{padding:16px}.pfo-header{padding:12px 16px}}
</style>

<!-- ═══ Marketplace Tile Grid ═══ -->
<div class="mp-grid">
  <?php
  $mpBgs = ['trendyol'=>'#fff7ed','hepsiburada'=>'#fff4ed','n11'=>'#faf5ff','amazon'=>'#fff8e1','pazarama'=>'#ecfeff','pttavm'=>'#fefce8','ciceksepeti'=>'#fdf2f8','idefix'=>'#eff6ff'];
  foreach ($marketplaces as $mp):
    $s = $mp['slug'];
    $mColor = $mpColors[$s] ?? '#1967F2';
    $mBg = $mpBgs[$s] ?? 'var(--bg-card)';
    $cnt = $mpProductCounts[$mp['id']] ?? 0;
    $isActive = !empty($mp['is_active']);
  ?>
  <div class="mp-tile <?= !$isActive ? 'mp-tile-inactive' : '' ?>"
       style="--mp-color:<?= $mColor ?>;--mp-bg:<?= $mBg ?>"
       <?php if ($isActive): ?>onclick="prodOpenWindow('<?= (int)$mp['id'] ?>','<?= htmlspecialchars($s) ?>','<?= htmlspecialchars($mp['display_name'] ?? $s, ENT_QUOTES) ?>','<?= $mColor ?>')"<?php endif; ?>
       data-mp-id="<?= (int)$mp['id'] ?>" data-mp-slug="<?= htmlspecialchars($s) ?>">
    <img class="mp-tile-logo" src="/public/assets/img/marketplaces/<?= htmlspecialchars($s) ?>.png" onerror="this.onerror=null;this.src='/public/assets/img/marketplaces/<?= htmlspecialchars($s) ?>.jpg'" alt="<?= htmlspecialchars($mp['display_name'] ?? $s) ?>">
    <span class="mp-tile-count <?= $cnt === 0 ? 'zero' : '' ?>"><?= $cnt ?> ürün</span>

  </div>
  <?php endforeach; ?>
</div>

<!-- ═══ Overlay — Marketplace Products ═══ -->
<div class="mp-overlay" id="prodOverlay">
  <div class="mp-window" id="prodWindow">
    <div class="mp-win-header" id="prodWinHeader">
      <img class="mp-win-logo" id="prodWinLogo" src="" alt="">
      <div class="mp-win-title" id="prodWinTitle">Ürünler</div>
      <button class="mp-win-close" onclick="prodCloseWindow()" title="Kapat (ESC)">
        <i data-lucide="x" style="width:20px;height:20px"></i>
      </button>
    </div>
    <div class="mp-win-toolbar">
      <div class="o-search" style="width:280px">
        <i data-lucide="search" style="width:14px;height:14px;color:var(--text-muted);flex-shrink:0"></i>
        <input type="text" id="prodWinSearch" placeholder="SKU, barkod, ürün adı...">
      </div>
      <button type="button" class="o-btn o-btn-success o-btn-sm" onclick="autoMatchOverlay()">
        <i data-lucide="zap" style="width:14px;height:14px"></i> Otomatik Eşleştir
      </button>
      <button type="button" class="o-btn o-btn-primary o-btn-sm" onclick="syncOverlay()">
        <i data-lucide="download" style="width:14px;height:14px"></i> Çek/Güncelle
      </button>
      <div style="margin-left:auto;font-size:12px;color:var(--text-muted)" id="prodWinInfo"></div>
    </div>
    <div class="mp-win-body">
      <table class="mp-table" id="prodWinTable">
        <thead><tr>
          <th style="width:50px"></th>
          <th style="min-width:220px">Ürün Bilgisi</th>
          <th>MP SKU</th>
          <th>Barkod</th>
          <th style="text-align:center">Fiyat</th>
          <th style="text-align:center">Stok</th>
          <th>Eşleşme</th>
          <th style="width:100px">İşlem</th>
        </tr></thead>
        <tbody id="prodWinBody"></tbody>
      </table>
      <div class="mp-pagination" id="prodWinPagination"></div>
    </div>
  </div>
</div>

<!-- ═══ Product Form Overlay ═══ -->
<div class="pfo-overlay" id="prodFormOverlay">
  <div class="pfo-window">
    <!-- Header -->
    <div class="pfo-header">
      <h2 id="pfoTitle"><i data-lucide="plus"></i> <span>Yeni Ürün Ekle</span></h2>
      <label style="display:inline-flex;align-items:center;gap:8px;cursor:pointer;font-size:13px;font-weight:600;color:var(--text-primary)">
        <span class="pfo-toggle"><input type="checkbox" id="pfoIsActive" checked><span class="pfo-toggle-slider"></span></span> Aktif
      </label>
      <button type="button" class="btn btn-primary btn-sm" id="pfoSaveBtn" onclick="prodFormSave()" style="display:flex;align-items:center;gap:6px;padding:8px 20px">
        <i data-lucide="save" style="width:14px;height:14px"></i> <span>Kaydet</span>
      </button>
      <button class="pfo-close" onclick="prodFormClose()" title="Kapat (ESC)"><i data-lucide="x" style="width:18px;height:18px"></i></button>
    </div>
    <!-- Main Content Area: Split Layout -->
    <div class="pfo-main">
      <!-- Left: Form Tabs + Body -->
      <div class="pfo-content">
        <!-- Tabs -->
        <div class="pfo-tabs">
          <div class="pfo-tab active" data-tab="general" onclick="pfoSwitchTab('general')"><i data-lucide="box"></i> Genel</div>
          <div class="pfo-tab" data-tab="images" onclick="pfoSwitchTab('images')"><i data-lucide="image"></i> Görseller & Açıklama</div>
          <div class="pfo-tab" data-tab="variants" onclick="pfoSwitchTab('variants')"><i data-lucide="layers"></i> Varyantlar</div>
          <div class="pfo-tab" data-tab="attrs" onclick="pfoSwitchTab('attrs')"><i data-lucide="sliders"></i> Özellikler & SEO</div>
        </div>
        <!-- Body -->
        <div class="pfo-body">
          <input type="hidden" id="pfoProductId" value="">

      <!-- TAB: Genel -->
      <div class="pfo-panel active" id="pfoPanel-general">
        <div class="pfo-sec">
          <div class="pfo-sec-head"><i data-lucide="box"></i> Temel Bilgiler</div>
          <div class="pfo-sec-body">
            <div class="pfo-row pfo-r3">
              <div class="pfo-f"><label>Ürün Adı *</label><input type="text" id="pfoName" placeholder="Ürün adı"></div>
              <div class="pfo-f"><label>SKU *</label><input type="text" id="pfoSku" placeholder="Stok kodu"></div>
              <div class="pfo-f"><label>Barkod</label><input type="text" id="pfoBarcode" placeholder="EAN13 / UPC"></div>
            </div>
            <div class="pfo-row pfo-r2">
              <div class="pfo-f">
                <label>Kategori</label>
                <select id="pfoCategory">
                  <option value="">— Seçiniz —</option>
                  <?php foreach($categories as $c): ?>
                  <option value="<?=$c['id']?>"><?=htmlspecialchars($c['name']??'')?></option>
                  <?php endforeach; ?>
                </select>
              </div>
              <div class="pfo-f">
                <label>Marka</label>
                <select id="pfoBrand">
                  <option value="">— Seçiniz —</option>
                  <?php foreach($brands as $b): ?>
                  <option value="<?=$b['id']?>"><?=htmlspecialchars($b['name']??'')?></option>
                  <?php endforeach; ?>
                </select>
              </div>
            </div>
          </div>
        </div>
        <div class="pfo-sec" style="margin-top:16px">
          <div class="pfo-sec-head"><i data-lucide="tag"></i> Fiyatlandırma</div>
          <div class="pfo-sec-body">
            <div class="pfo-row pfo-r4">
              <div class="pfo-f"><label>Satış Fiyatı (₺) *</label><input type="number" id="pfoBasePrice" step="0.01" placeholder="0.00"></div>
              <div class="pfo-f"><label>Liste Fiyatı (₺)</label><input type="number" id="pfoListPrice" step="0.01" placeholder="0.00"></div>
              <div class="pfo-f"><label>Maliyet (₺)</label><input type="number" id="pfoCostPrice" step="0.01" placeholder="0.00"></div>
              <div class="pfo-f">
                <label>KDV %</label>
                <select id="pfoTaxRate">
                  <option value="0">%0</option>
                  <option value="1">%1</option>
                  <option value="10">%10</option>
                  <option value="20" selected>%20</option>
                </select>
              </div>
            </div>
          </div>
        </div>
        <div class="pfo-sec" style="margin-top:16px">
          <div class="pfo-sec-head"><i data-lucide="package"></i> Stok & Kargo</div>
          <div class="pfo-sec-body">
            <div class="pfo-row pfo-r4">
              <div class="pfo-f"><label>Stok Adedi</label><input type="number" id="pfoStockQty" value="0"></div>
              <div class="pfo-f"><label>Min. Stok Uyarı</label><input type="number" id="pfoMinStock" value="5"></div>
              <div class="pfo-f"><label>Ağırlık (kg)</label><input type="number" id="pfoWeight" step="0.001" placeholder="—"></div>
              <div class="pfo-f"><label>Desi</label><input type="number" id="pfoDesi" step="0.01" placeholder="—"></div>
            </div>
            <div class="pfo-row pfo-r4" style="margin-top:12px">
              <div class="pfo-f">
                <label>Birim</label>
                <select id="pfoUnit">
                  <option value="adet">Adet</option>
                  <option value="kg">KG</option>
                  <option value="lt">LT</option>
                  <option value="mt">MT</option>
                  <option value="paket">Paket</option>
                </select>
              </div>
            </div>
          </div>
        </div>
      </div>

      <!-- TAB: Görseller & Açıklama -->
      <div class="pfo-panel" id="pfoPanel-images">
        <div class="pfo-sec">
          <div class="pfo-sec-head"><i data-lucide="image"></i> Ürün Görselleri</div>
          <div class="pfo-sec-body">
            <div class="pfo-imgs" id="pfoImageGallery">
              <div class="pfo-img-add" onclick="document.getElementById('pfoFileInput').click()">
                <i data-lucide="plus"></i> Ekle
              </div>
            </div>
            <input type="file" id="pfoFileInput" multiple accept="image/*" style="display:none" onchange="pfoHandleFileUpload(this.files)">
            <div class="pfo-drop" id="pfoDropZone" onclick="document.getElementById('pfoFileInput').click()">
              <i data-lucide="upload-cloud" style="width:24px;height:24px;margin-bottom:4px;display:block;margin:0 auto 6px"></i>
              Görselleri sürükle-bırak veya tıklayarak yükle · Max 5MB
            </div>
            <div class="pfo-url-row">
              <input type="text" id="pfoNewImageUrl" placeholder="https://... (resim URL'si)">
              <button type="button" class="btn btn-secondary btn-sm" onclick="pfoAddImageFromUrl()">URL Ekle</button>
            </div>
          </div>
        </div>
        <div class="pfo-desc-grid" style="margin-top:16px">
          <div class="pfo-sec">
            <div class="pfo-sec-head"><i data-lucide="file-text"></i> Ürün Açıklaması</div>
            <div class="pfo-sec-body">
              <div class="pfo-editor-wrap" id="pfoDescWrap"><div id="pfoDescEditor"></div></div>
            </div>
          </div>
          <div class="pfo-sec">
            <div class="pfo-sec-head"><i data-lucide="align-left"></i> Kısa Açıklama</div>
            <div class="pfo-sec-body">
              <div class="pfo-editor-wrap" id="pfoShortDescWrap"><div id="pfoShortDescEditor"></div></div>
            </div>
          </div>
        </div>
      </div>

      <!-- TAB: Varyantlar -->
      <div class="pfo-panel" id="pfoPanel-variants">
        <div class="pfo-sec">
          <div class="pfo-sec-head"><i data-lucide="layers"></i> Varyantlar <span style="margin-left:auto;font-size:10px;font-weight:400;color:var(--text-muted)">Beden, renk gibi farklı seçenekler</span></div>
          <div class="pfo-sec-body">
            <div id="pfoVariantsList">
              <div class="pfo-vr-row pfo-vr-header">
                <div>Varyant Adı</div><div>SKU</div><div>Barkod</div><div>Fiyat (₺)</div><div>Stok</div><div></div>
              </div>
            </div>
            <button type="button" class="pfo-vr-add" onclick="pfoAddVariant()">
              <i data-lucide="plus" style="width:12px;height:12px"></i> Varyant Ekle
            </button>
          </div>
        </div>
      </div>

      <!-- TAB: Özellikler & SEO -->
      <div class="pfo-panel" id="pfoPanel-attrs">
        <div class="pfo-sec">
          <div class="pfo-sec-head"><i data-lucide="sliders"></i> Ürün Özellikleri</div>
          <div class="pfo-sec-body" id="pfoAttrsContainer">
            <div style="text-align:center;padding:20px;color:var(--text-muted);font-size:13px">Özellikler yükleniyor...</div>
          </div>
        </div>
        <div class="pfo-sec" style="margin-top:16px">
          <div class="pfo-sec-head"><i data-lucide="search"></i> SEO Ayarları</div>
          <div class="pfo-sec-body">
            <div class="pfo-row pfo-r2">
              <div class="pfo-f"><label>SEO Başlık</label><input type="text" id="pfoSeoTitle" placeholder="Arama motorlarında görünecek başlık" oninput="pfoUpdateSeoPreview()"></div>
              <div class="pfo-f"><label>SEO Açıklama</label><textarea id="pfoSeoDesc" placeholder="Meta açıklama" style="min-height:50px" oninput="pfoUpdateSeoPreview()"></textarea></div>
            </div>
            <div class="pfo-seo-preview" id="pfoSeoPreview">
              <p class="seo-title" id="pfoSeoPreviewTitle">Ürün başlığı burada görünecek</p>
              <p class="seo-url">OzyConnect.com/urunler/urun-adi</p>
              <p class="seo-desc" id="pfoSeoPreviewDesc">Ürün açıklaması burada görünecek...</p>
            </div>
          </div>
        </div>
      </div>

      </div><!-- /pfo-body -->
      </div><!-- /pfo-content -->

      <!-- Right Sidebar: Pazaryerleri -->
      <div class="pfo-sidebar" id="pfoSidebar">
        <div class="pfo-sidebar-head">
          <span style="display:flex;align-items:center;gap:6px"><i data-lucide="store"></i> Pazaryerleri</span>
          <button type="button" class="btn btn-primary btn-sm" onclick="pfoSaveMpSettings()" style="font-size:10px;padding:3px 10px;display:flex;align-items:center;gap:4px"><i data-lucide="save" style="width:11px;height:11px"></i> Kaydet</button>
        </div>
        <div class="pfo-sidebar-body" id="pfoMpContainer">
          <div style="text-align:center;padding:32px;color:var(--text-muted);font-size:13px">
            <i data-lucide="loader" style="width:20px;height:20px;animation:spin 1s linear infinite;display:inline-block;vertical-align:middle;margin-right:6px"></i> Yükleniyor...
          </div>
        </div>
      </div>

    </div><!-- /pfo-main -->
    <!-- Footer -->
    <div class="pfo-footer">
      <div style="font-size:11px;color:var(--text-muted)" id="pfoFooterInfo"></div>
      <div style="display:flex;gap:8px">
        <button type="button" class="btn btn-secondary btn-sm" onclick="prodFormClose()">İptal</button>
        <button type="button" class="btn btn-primary btn-sm" onclick="prodFormSave()" style="display:flex;align-items:center;gap:6px;padding:8px 20px">
          <i data-lucide="save" style="width:14px;height:14px"></i> <span id="pfoSaveBtnText2">Kaydet</span>
        </button>
      </div>
    </div>
  </div>
</div>

<!-- ═══ Sync Box ═══ -->
<div class="p-sync-box" id="pSyncBox">
    <div style="display:flex;align-items:center;gap:10px">
        <i data-lucide="refresh-cw" style="width:18px;height:18px;color:var(--primary);animation:spin 1s linear infinite"></i>
        <div><strong id="pSyncTitle">İşlem yapılıyor...</strong><div id="pSyncStatus" style="font-size:12px;color:var(--text-muted);margin-top:2px"></div></div>
    </div>
    <div class="p-sync-bar"><div class="p-sync-bar-fill" id="pSyncBarFill"></div></div>
</div>

<!-- ═══ SYSTEM TABLE ═══ -->
<div class="p-tab-panel active" id="panel-system">

<!-- Toolbar -->
<div class="o-toolbar">
    <div class="o-search">
        <i data-lucide="search" style="width:16px;height:16px;color:var(--text-muted);flex-shrink:0"></i>
        <input type="text" id="dtSearch" placeholder="SKU, barkod, ürün adı..." value="<?= htmlspecialchars($filters['search'] ?? '') ?>">
    </div>
    <select id="dtCategory" class="o-sel">
        <option value="">Tüm Kategoriler</option>
        <?php foreach ($categories as $c): ?>
            <option value="<?= $c['id'] ?>" <?= ($filters['category_id'] ?? '') == $c['id'] ? 'selected' : '' ?>><?= htmlspecialchars($c['name'] ?? '') ?></option>
        <?php endforeach; ?>
    </select>
    <select id="dtStock" class="o-sel">
        <option value="">Stok Durumu</option>
        <option value="in_stock" <?= ($filters['stock_status'] ?? '') === 'in_stock' ? 'selected' : '' ?>>Stokta</option>
        <option value="low" <?= ($filters['stock_status'] ?? '') === 'low' ? 'selected' : '' ?>>Düşük Stok</option>
        <option value="out" <?= ($filters['stock_status'] ?? '') === 'out' ? 'selected' : '' ?>>Tükendi</option>
    </select>
    <select id="dtActive" class="o-sel">
        <option value="">Tüm Durumlar</option>
        <option value="1" <?= ($filters['is_active'] ?? '') === '1' ? 'selected' : '' ?>>Aktif</option>
        <option value="0" <?= ($filters['is_active'] ?? '') === '0' ? 'selected' : '' ?>>Pasif</option>
    </select>
    <button type="button" class="o-btn o-btn-primary o-btn-sm" onclick="reloadTable()"><i data-lucide="filter" style="width:14px;height:14px"></i> Filtrele</button>
    <button type="button" class="o-btn o-btn-outline o-btn-sm" onclick="clearFilters()"><i data-lucide="x" style="width:14px;height:14px"></i> Temizle</button>
    <div class="o-actions">
        <button type="button" class="o-btn o-btn-outline o-btn-sm" id="archiveToggle" onclick="toggleArchiveView()" style="font-size:11px">
            <i data-lucide="archive" style="width:14px;height:14px"></i> <span id="archiveToggleLabel">Arşivi Göster</span>
        </button>
        <a href="javascript:void(0)" class="o-btn o-btn-success o-btn-sm" onclick="prodFormOpen(null)"><i data-lucide="plus" style="width:14px;height:14px"></i> Yeni Ürün</a>
        <button type="button" class="o-btn o-btn-success o-btn-sm" onclick="doSync()" title="Tüm pazaryerlerinden ürünleri çek"><i data-lucide="refresh-cw" style="width:14px;height:14px"></i> Senkronize Et</button>
    </div>
</div>




<!-- Table Card -->
<div class="o-card">
<!-- Bulk bar -->
<div class="o-bulk" id="bulkBar">
    <span><strong id="bulkCount">0</strong> ürün seçildi</span>
    <div class="o-bulk-actions">
        <button class="o-btn o-btn-success o-btn-sm" onclick="bulkAction('activate')"><i data-lucide="check" style="width:12px;height:12px"></i> Aktif Et</button>
        <button class="o-btn o-btn-warning o-btn-sm" onclick="bulkAction('deactivate')"><i data-lucide="pause" style="width:12px;height:12px"></i> Pasif Et</button>
        <button class="o-btn o-btn-primary o-btn-sm" onclick="bulkAction('sync')"><i data-lucide="refresh-cw" style="width:12px;height:12px"></i> Senkronize Et</button>
        <button class="o-btn o-btn-outline o-btn-sm" id="bulkArchiveBtn" onclick="bulkAction('archive')"><i data-lucide="archive" style="width:12px;height:12px"></i> Arşive Taşı</button>
        <button class="o-btn o-btn-danger o-btn-sm" onclick="bulkAction('delete')"><i data-lucide="trash-2" style="width:12px;height:12px"></i> Sil</button>
    </div>
    <button class="o-btn o-btn-outline o-btn-sm" onclick="clearSelection()"><i data-lucide="x" style="width:12px;height:12px"></i></button>
</div>

<div style="overflow-x:auto">
<table id="productsTable" class="o-table" style="width:100%">
<thead><tr>
    <th style="width:36px"><input type="checkbox" id="selectAll" onchange="toggleAll(this)"></th>
    <th style="min-width:280px">Ürün Bilgisi</th>
    <th>Kategori</th>
    <th>Marka</th>
    <th style="text-align:center">Fiyat</th>
    <th style="text-align:center">Stok</th>
    <th style="text-align:center">Pazaryeri</th>
    <th style="text-align:center">Durum</th>
    <th style="min-width:170px">İşlemler</th>
</tr></thead>
<tbody></tbody>
</table>
</div>
<div class="dt-footer">
    <div id="dtInfo"></div>
    <div id="dtPaginate"></div>
</div>
</div>

<!-- Lightbox -->
<div class="lightbox-overlay" id="lightbox" onclick="closeLightbox()">
    <button class="lightbox-close" onclick="closeLightbox()"><i data-lucide="x" style="width:20px;height:20px"></i></button>
    <img src="" alt="" class="lightbox-img" id="lightboxImg" onclick="event.stopPropagation()">
</div>

<!-- Sync Progress Modal -->
<div class="modal-overlay" id="syncModal">
    <div class="modal-box" style="width:420px">
        <div class="modal-header">
            <h3 id="syncModalTitle">Senkronizasyon Durumu</h3>
            <button class="modal-close" onclick="closeSyncModal()"><i data-lucide="x" style="width:18px;height:18px"></i></button>
        </div>
        <div class="modal-body" id="syncModalBody">
            <div class="sync-progress" id="syncProgress"></div>
        </div>
        <div class="modal-footer">
            <button class="o-btn o-btn-outline o-btn-sm" onclick="closeSyncModal()">Kapat</button>
        </div>
    </div>
</div>

<!-- Bulk Action Form -->
<form id="bulkForm" method="POST" action="/products/bulk-action" style="display:none">
    <input type="hidden" name="_csrf_token" value="<?= htmlspecialchars($csrf) ?>">
    <input type="hidden" name="product_ids" id="bulkProductIds">
    <input type="hidden" name="action" id="bulkActionType">
    <input type="hidden" name="bulk_value" id="bulkValue">
</form>

</div><!-- /panel-system -->

<!-- ═══ Confirm Modal ═══ -->
<div class="confirm-overlay" id="confirmModal">
    <div class="confirm-box">
        <div class="confirm-icon warn" id="confirmIcon">⚠️</div>
        <div class="confirm-title" id="confirmTitle">Emin misiniz?</div>
        <div class="confirm-msg" id="confirmMsg"></div>
        <div class="confirm-btns">
            <button type="button" class="confirm-btn-cancel" id="confirmCancel">İptal</button>
            <button type="button" class="confirm-btn-ok" id="confirmOk">Evet, Devam Et</button>
        </div>
    </div>
</div>

<link href="/public/assets/vendor/quill/quill.snow.css" rel="stylesheet">
<script src="/public/assets/vendor/quill/quill.js"></script>
<script>
const CSRF = <?= json_encode($csrf) ?>;
const MP_COLORS = <?= json_encode($mpColors, JSON_UNESCAPED_UNICODE) ?>;
const ALL_MP = <?= json_encode(array_map(fn($m) => ['id'=>$m['id'],'slug'=>$m['slug'],'name'=>$m['display_name']??$m['slug'],'active'=>!empty($m['is_active'])], $marketplaces), JSON_UNESCAPED_UNICODE) ?>;
let selectedIds = new Set();
let currentMp = <?= json_encode($currentMp) ?>;
let showArchived = false;

function esc(s){ const d=document.createElement('div'); d.textContent=s||''; return d.innerHTML; }
function fmtMoney(n){ return n.toLocaleString('tr-TR',{minimumFractionDigits:2,maximumFractionDigits:2})+' ₺'; }

// ── Marketplace strip popover ──
const PROD_MP_DATA = new Map(); // productId → mpMap
(function(){
    let pop = document.createElement('div');
    pop.className = 'mp-popover';
    pop.id = 'mpPopover';
    document.body.appendChild(pop);
    let hideTimer = null;

    document.addEventListener('mouseenter', function(e){
        const strip = e.target.closest('.mp-strip');
        if(!strip) return;
        clearTimeout(hideTimer);
        const wrap = strip.closest('.mp-strip-wrap');
        const pid = wrap ? wrap.dataset.pid : null;
        if(!pid) return;
        const mpMap = PROD_MP_DATA.get(Number(pid)) || {};

        let html = '';
        ALL_MP.forEach(m => {
            const mp = mpMap[m.slug];
            let statusCls, statusTxt;
            if(!mp){
                statusCls = 's-none'; statusTxt = '—';
            } else if(mp.sync_error){
                statusCls = 's-error'; statusTxt = 'Hata';
            } else if(parseInt(mp.is_active)){
                statusCls = 's-active'; statusTxt = 'Aktif';
            } else {
                statusCls = 's-inactive'; statusTxt = 'Pasif';
            }
            const rowCls = !mp ? 'is-none' : '';
            html += '<div class="mp-pop-row '+rowCls+'">';
            html += '<img src="/public/assets/img/marketplaces/'+esc(m.slug)+'.png" alt="'+esc(m.name)+'">';
            html += '<span class="mp-pop-name">'+esc(m.name)+'</span>';
            html += '<span class="mp-pop-status '+statusCls+'">'+statusTxt+'</span>';
            html += '</div>';
        });
        pop.innerHTML = html;
        pop.classList.add('show');

        const rect = strip.getBoundingClientRect();
        let top = rect.bottom + 6;
        let left = rect.left + (rect.width/2) - (pop.offsetWidth/2);
        // Keep within viewport
        if(left < 8) left = 8;
        if(left + pop.offsetWidth > window.innerWidth - 8) left = window.innerWidth - 8 - pop.offsetWidth;
        if(top + pop.offsetHeight > window.innerHeight - 8) top = rect.top - pop.offsetHeight - 6;
        pop.style.top = top + 'px';
        pop.style.left = left + 'px';
    }, true);

    document.addEventListener('mouseleave', function(e){
        const strip = e.target.closest('.mp-strip');
        if(!strip) return;
        hideTimer = setTimeout(()=>{ pop.classList.remove('show'); }, 120);
    }, true);
})();

const table = $('#productsTable').DataTable({
    processing: true,
    serverSide: true,
    ajax: {
        url: '/products/ajax',
        data: function(d) {
            d.marketplace_id = currentMp || '';
            d.category_id = document.getElementById('dtCategory').value;
            d.stock_status = document.getElementById('dtStock').value;
            d.is_active = document.getElementById('dtActive').value;
            if(showArchived) d.show_archived = '1';
        },
        dataSrc: function(json) {
            if (json.mpProductCounts) updateMpCounts(json.mpProductCounts, json.recordsTotal);
            return json.data;
        }
    },
    columns: [
        { data: null, orderable: false, className: 'dt-center', width: '36px',
          render: function(d,t,r){
              const chk = selectedIds.has(r.id) ? 'checked' : '';
              return '<input type="checkbox" class="rowCheck" value="'+r.id+'" '+chk+'>';
          }
        },
        // Ürün Bilgisi: Resim + İsim + SKU + Barkod (Trendyol tarzı)
        { data: null, orderable: true,
          render: function(d,t,r){
              let img = '';
              if(r.main_image){
                  img = '<img src="'+esc(r.main_image)+'" class="p-thumb" onclick="event.stopPropagation();openLightbox(\''+esc(r.main_image)+'\')" onerror="this.outerHTML=\'<div class=p-thumb-empty>\uD83D\uDCF7</div>\'" loading="lazy">';
              } else {
                  img = '<div class="p-thumb-empty">\uD83D\uDCF7</div>';
              }
              
              let html = '<div style="display:flex;align-items:center;gap:12px">';
              html += img;
              html += '<div style="min-width:0">';
              html += '<a href="/products/'+r.id+'" style="font-weight:700;font-size:13px;color:var(--text-primary);text-decoration:none;display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:hidden;line-height:1.3">'+esc(r.name)+'</a>';
              
              // SKU + Barkod + Varyant badge alt satır
              let meta = [];
              if(r.sku) meta.push('<span title="SKU">\uD83D\uDCE6 '+esc(r.sku)+'</span>');
              if(r.barcode) meta.push('<span title="Barkod">\uD83C\uDFF7 '+esc(r.barcode)+'</span>');
              if(meta.length > 0){
                  html += '<div style="font-size:11px;color:var(--text-muted);margin-top:3px;display:flex;gap:10px;flex-wrap:wrap;font-family:\'Fira Code\',monospace">'+meta.join('')+'</div>';
              }

              // Varyant badge
              if(r.variant_count > 0){
                  html += '<div style="margin-top:4px"><span style="display:inline-flex;align-items:center;gap:4px;padding:2px 8px;border-radius:6px;font-size:10px;font-weight:700;background:#ede9fe;color:#7c3aed;border:1px solid #ddd6fe"><svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><rect x="2" y="7" width="20" height="14" rx="2" ry="2"/><path d="M16 21V5a2 2 0 0 0-2-2h-4a2 2 0 0 0-2 2v16"/></svg> '+r.variant_count+' Varyant</span></div>';
              }
              
              html += '</div></div>';
              return html;
          }
        },
        // Kategori
        { data: 'category_name', orderable: true,
          render: function(d){ 
              return d ? '<span style="font-size:12px;color:var(--text-secondary)">'+esc(d)+'</span>' 
                       : '<span style="color:var(--text-muted);font-size:11px">—</span>'; 
          }
        },
        // Marka
        { data: 'brand_name', orderable: true,
          render: function(d){ 
              return d ? '<span style="font-size:12px;font-weight:700;color:var(--text-secondary)">'+esc(d)+'</span>' 
                       : '<span style="color:var(--text-muted);font-size:11px">—</span>'; 
          }
        },
        // Fiyat (inline editable)
        { data: null, orderable: true, className: 'dt-center',
          render: function(d,t,r){
              if(r.base_price > 0){
                  return '<div class="inline-edit" onclick="startInlineEdit(event, '+r.id+', \'base_price\', '+r.base_price+')" title="Tıklayarak fiyat düzenle" style="justify-content:center">' 
                    +'<span style="font-weight:700;font-size:14px;white-space:nowrap;color:var(--text-primary)">'+fmtMoney(r.base_price)+'</span>'
                    +'<span class="edit-icon"><svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21.174 6.812a1 1 0 0 0-3.986-3.987L3.842 16.174a2 2 0 0 0-.5.83l-1.321 4.352a.5.5 0 0 0 .623.622l4.353-1.32a2 2 0 0 0 .83-.497z"/></svg></span></div>';
              }
              return '<span class="badge badge-danger" style="font-size:10px">Fiyat Eksik</span>';
          }
        },
        // Stok (inline editable)
        { data: null, orderable: true, className: 'dt-center',
          render: function(d,t,r){
              const cls = r.stock_qty <= 0 ? 'badge-danger' : (r.stock_qty <= r.min_stock_alert ? 'badge-warning' : 'badge-success');
              return '<div class="inline-edit" onclick="startInlineEdit(event, '+r.id+', \'stock_qty\', '+r.stock_qty+')" title="Tıklayarak stok düzenle" style="display:inline-flex;justify-content:center">' 
                +'<span class="badge '+cls+'" style="cursor:pointer;font-size:12px;padding:4px 12px;min-width:36px;justify-content:center">'+r.stock_qty+'</span>'
                +'<span class="edit-icon"><svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21.174 6.812a1 1 0 0 0-3.986-3.987L3.842 16.174a2 2 0 0 0-.5.83l-1.321 4.352a.5.5 0 0 0 .623.622l4.353-1.32a2 2 0 0 0 .83-.497z"/></svg></span></div>';
          }
        },
        // Pazaryeri — renk şerit bar + hover popover
        { data: null, orderable: false, className: 'dt-center',
          render: function(d,t,r){
              const mpMap = {};
              let activeCount = 0;
              if(r.marketplaces) r.marketplaces.forEach(mp => { mpMap[mp.slug] = mp; if(parseInt(mp.is_active)) activeCount++; });
              PROD_MP_DATA.set(r.id, mpMap);
              const total = ALL_MP.length;
              
              let h = '<div class="mp-strip-wrap" data-pid="'+r.id+'">';
              h += '<div class="mp-strip">';
              ALL_MP.forEach(m => {
                  const mp = mpMap[m.slug];
                  const color = MP_COLORS[m.slug] || '#1967F2';
                  let cls;
                  if(!mp) cls = 'none';
                  else if(mp.sync_error) cls = 'error';
                  else if(parseInt(mp.is_active)) cls = 'active';
                  else cls = 'inactive';
                  h += '<span class="mp-seg '+cls+'" style="background-color:'+color+'"></span>';
              });
              h += '</div>';
              h += '<span class="mp-count">'+activeCount+'/'+total+'</span>';
              h += '</div>';
              return h;
          }
        },
        // Durum
        { data: 'is_active', orderable: true, className: 'dt-center',
          render: function(d){
              return d ? '<span class="badge badge-success">Aktif</span>' : '<span class="badge badge-secondary">Pasif</span>';
          }
        },
        // İşlemler
        { data: null, orderable: false,
          render: function(d,t,r){
              let h = '<div class="o-row-actions">';
              h += '<a href="javascript:void(0)" onclick="event.stopPropagation();prodFormOpen('+r.id+')" class="o-ra"><i data-lucide="edit-3" style="width:12px;height:12px"></i> Düzenle</a>';
              h += '<a href="/products/'+r.id+'" class="o-ra"><i data-lucide="eye" style="width:12px;height:12px"></i> Detay</a>';
              if(r.marketplaces && r.marketplaces.length > 0){
                  h += '<button class="o-ra o-ra-sync" onclick="event.stopPropagation();syncSingleProduct('+r.id+')"><i data-lucide="refresh-cw" style="width:12px;height:12px"></i> Sync</button>';
              }
              h += '</div>';
              return h;
          }
        }
    ],
    order: [[1, 'desc']],
    pageLength: 25,
    lengthMenu: [25, 50, 100],
    searching: true,
    search: { search: <?= json_encode($filters['search'] ?? '') ?> },
    dom: 'rt<"dt-footer"ip>',
    language: {
        processing: '<div style="display:flex;align-items:center;gap:8px"><i data-lucide="loader" style="width:16px;height:16px;animation:spin 1s linear infinite"></i> Yükleniyor...</div>',
        emptyTable: '<div class="o-empty"><i data-lucide="package" style="width:48px;height:48px;margin:0 auto 12px;display:block;color:var(--text-muted)"></i>Ürün bulunamadı</div>',
        zeroRecords: '<div class="o-empty"><i data-lucide="search-x" style="width:48px;height:48px;margin:0 auto 12px;display:block;color:var(--text-muted)"></i>Aramanıza uygun ürün bulunamadı</div>',
        info: 'Toplam _TOTAL_ ürün · Sayfa _PAGE_/_PAGES_',
        infoEmpty: 'Ürün yok',
        infoFiltered: '(_MAX_ kayıttan filtrelendi)',
        paginate: { previous: '←', next: '→' }
    },
    drawCallback: function() {
        if(typeof lucide !== 'undefined') lucide.createIcons();
        updateBulkBar();
        document.querySelectorAll('.rowCheck').forEach(cb => {
            if(selectedIds.has(parseInt(cb.value))) cb.checked = true;
        });
    }
});

// ═══ SEARCH ═══
let searchTimer;
document.getElementById('dtSearch').addEventListener('input', function(){
    clearTimeout(searchTimer);
    const val = this.value;
    searchTimer = setTimeout(() => { table.search(val).draw(); }, 400);
});
document.getElementById('dtSearch').addEventListener('keydown', function(e){
    if(e.key === 'Enter') { e.preventDefault(); clearTimeout(searchTimer); table.search(this.value).draw(); }
});

// ═══ FILTERS ═══
['dtCategory','dtStock','dtActive'].forEach(id => {
    document.getElementById(id).addEventListener('change', () => table.ajax.reload());
});

function reloadTable(){ table.ajax.reload(); }

function clearFilters(){
    document.getElementById('dtSearch').value = '';
    document.getElementById('dtCategory').value = '';
    document.getElementById('dtStock').value = '';
    document.getElementById('dtActive').value = '';
    currentMp = '';
    table.search('').ajax.reload();
}
function toggleArchiveView(){
    showArchived = !showArchived;
    const label = document.getElementById('archiveToggleLabel');
    const btn = document.getElementById('archiveToggle');
    const archiveBtn = document.getElementById('bulkArchiveBtn');
    if(showArchived){
        label.textContent = 'Aktif Ürünleri Göster';
        btn.classList.add('o-btn-warning');
        btn.classList.remove('o-btn-outline');
        if(archiveBtn) { archiveBtn.textContent = ''; archiveBtn.innerHTML = '<i data-lucide="archive-restore" style="width:12px;height:12px"></i> Arşivden Çıkar'; archiveBtn.onclick = function(){ bulkAction('unarchive'); }; }
    } else {
        label.textContent = 'Arşivi Göster';
        btn.classList.remove('o-btn-warning');
        btn.classList.add('o-btn-outline');
        if(archiveBtn) { archiveBtn.innerHTML = '<i data-lucide="archive" style="width:12px;height:12px"></i> Arşive Taşı'; archiveBtn.onclick = function(){ bulkAction('archive'); }; }
    }
    if(typeof lucide !== 'undefined') lucide.createIcons();
    table.ajax.reload();
}
function updateMpCounts(counts, total){
    // Update tile count badges
    document.querySelectorAll('.mp-tile').forEach(tile => {
        const mpId = tile.dataset.mpId;
        if (!mpId) return;
        const cnt = counts[mpId] || 0;
        const badge = tile.querySelector('.mp-tile-count');
        if (badge) {
            badge.textContent = cnt + ' ürün';
            if (cnt === 0) badge.classList.add('zero'); else badge.classList.remove('zero');
        }
    });
}

// ═══ BULK SELECTION ═══
$('#productsTable tbody').on('change', '.rowCheck', function(){
    const id = parseInt(this.value);
    if(this.checked) selectedIds.add(id); else selectedIds.delete(id);
    updateBulkBar();
});
function toggleAll(c){
    document.querySelectorAll('.rowCheck').forEach(x => {
        x.checked = c.checked;
        const id = parseInt(x.value);
        if(c.checked) selectedIds.add(id); else selectedIds.delete(id);
    });
    updateBulkBar();
}
function updateBulkBar(){
    const bar = document.getElementById('bulkBar');
    if(selectedIds.size > 0){
        bar.classList.add('show');
        document.getElementById('bulkCount').textContent = selectedIds.size;
    } else {
        bar.classList.remove('show');
        document.getElementById('selectAll').checked = false;
    }
}
function clearSelection(){
    selectedIds.clear();
    document.querySelectorAll('.rowCheck,#selectAll').forEach(c => c.checked=false);
    updateBulkBar();
}

// ═══ BULK ACTIONS ═══
async function bulkAction(action){
    const ids = Array.from(selectedIds);
    if(ids.length === 0){ Toast.show('Lütfen en az bir ürün seçin', 'warning'); return; }

    let msg = '';
    switch(action){
        case 'activate': msg = ids.length+' ürün aktif edilecek. Devam?'; break;
        case 'deactivate': msg = ids.length+' ürün pasif edilecek. Devam?'; break;
        case 'delete': msg = ids.length+' ürün kalıcı olarak silinecek! Bu işlem geri alınamaz. Devam?'; break;
        case 'sync': msg = ids.length+' ürün senkronize edilecek. Devam?'; break;
        case 'archive': msg = ids.length+' ürün arşive taşınacak. Devam?'; break;
        case 'unarchive': msg = ids.length+' ürün arşivden çıkarılacak. Devam?'; break;
    }

    // Use async confirm to avoid browser blocking
    const confirmed = await new Promise(resolve => {
        // Check if customConfirm is available (from categories page pattern)
        if(typeof customConfirm === 'function') {
            customConfirm(msg, {title:'Toplu İşlem', icon:'⚡', iconClass:'warn', okText:'Evet, Devam Et'}).then(resolve);
        } else {
            // Fallback: create simple modal
            const ok = window.confirm(msg);
            resolve(ok);
        }
    });
    if(!confirmed) return;

    document.getElementById('bulkProductIds').value = ids.join(',');
    document.getElementById('bulkActionType').value = action;
    document.getElementById('bulkForm').submit();
}

// ═══ INLINE EDITING ═══
let activeInlineEditor = null;

function startInlineEdit(event, productId, field, currentValue){
    event.stopPropagation();

    // Close any existing editor
    if(activeInlineEditor) cancelInlineEdit();

    const cell = event.currentTarget;
    activeInlineEditor = { cell, productId, field, originalHTML: cell.innerHTML };

    const isPrice = field !== 'stock_qty';
    const inputType = isPrice ? 'number' : 'number';
    const step = isPrice ? '0.01' : '1';
    const displayVal = isPrice ? currentValue.toFixed(2) : currentValue;

    cell.innerHTML = `<input type="${inputType}" class="inline-input" value="${displayVal}" step="${step}" min="0"
        onblur="saveInlineEdit(${productId}, '${field}', this.value)"
        onkeydown="handleInlineKey(event, ${productId}, '${field}')">`;

    const input = cell.querySelector('input');
    input.focus();
    input.select();
}

function handleInlineKey(event, productId, field){
    if(event.key === 'Enter'){ event.preventDefault(); saveInlineEdit(productId, field, event.target.value); }
    if(event.key === 'Escape'){ event.preventDefault(); cancelInlineEdit(); }
}

function cancelInlineEdit(){
    if(!activeInlineEditor) return;
    activeInlineEditor.cell.innerHTML = activeInlineEditor.originalHTML;
    activeInlineEditor = null;
}

async function saveInlineEdit(productId, field, value){
    if(!activeInlineEditor) return;

    const cell = activeInlineEditor.cell;
    const numValue = field === 'stock_qty' ? parseInt(value) : parseFloat(value);

    if(isNaN(numValue) || numValue < 0){
        Toast.show('Geçersiz değer', 'warning');
        cancelInlineEdit();
        return;
    }

    // Show saving state
    cell.innerHTML = '<span style="color:var(--primary);font-size:11px"><i data-lucide="loader" style="width:12px;height:12px;animation:spin 1s linear infinite"></i> Kaydediliyor...</span>';
    if(typeof lucide !== 'undefined') lucide.createIcons();

    try {
        const res = await fetch('/products/inline-update', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': CSRF },
            body: JSON.stringify({ product_id: productId, field: field, value: numValue })
        });
        const data = await res.json();

        if(data.success){
            // Show sync status
            if(data.data && data.data.sync && data.data.sync.length > 0){
                showSyncProgress(productId, field, data.data.sync);
            }

            Toast.show('Güncellendi: ' + (field === 'stock_qty' ? 'Stok' : 'Fiyat'), 'success', 3000);
            activeInlineEditor = null;
            table.ajax.reload(null, false);
        } else {
            Toast.show('Hata: ' + (data.message || 'Bilinmeyen hata'), 'error');
            cancelInlineEdit();
        }
    } catch(err){
        Toast.show('Bağlantı hatası: ' + err.message, 'error');
        cancelInlineEdit();
    }
}

// ═══ SYNC ═══
function showSyncProgress(productId, field, syncItems){
    const modal = document.getElementById('syncModal');
    const title = document.getElementById('syncModalTitle');
    const body = document.getElementById('syncProgress');

    title.textContent = (field === 'stock_qty' ? 'Stok' : 'Fiyat') + ' Senkronizasyonu - #' + productId;

    let html = '';
    syncItems.forEach(item => {
        const color = MP_COLORS[item.slug] || '#1967F2';
        const statusCls = item.status === 'success' ? 'ok' : (item.status === 'error' ? 'fail' : 'wait');
        const statusIcon = item.status === 'success' ? '✓' : (item.status === 'error' ? '✕' : '⏳');
        const statusText = item.status === 'success' ? 'Başarılı' : (item.status === 'error' ? 'Başarısız' : 'Bekliyor');

        html += `<div class="sync-line">
            <span class="mp-name" style="color:${color}">${esc(item.display_name)}</span>
            <span class="sync-status ${statusCls}">${statusIcon} ${statusText}</span>
            <span style="font-size:10px;color:var(--text-muted);flex:1;text-align:right">${esc(item.message || '')}</span>
            ${item.status === 'error' ? '<button class="o-ra" style="font-size:10px;color:var(--warning)" onclick="retrySyncItem('+productId+', '+item.marketplace_id+')">🔄 Tekrar Dene</button>' : ''}
        </div>`;
    });

    body.innerHTML = html;
    modal.classList.add('show');
}

function closeSyncModal(){
    document.getElementById('syncModal').classList.remove('show');
}

async function syncSingleProduct(productId){
    const modal = document.getElementById('syncModal');
    const title = document.getElementById('syncModalTitle');
    const body = document.getElementById('syncProgress');

    title.textContent = 'Ürün Senkronizasyonu - #' + productId;
    body.innerHTML = '<div style="text-align:center;padding:20px;color:var(--text-muted)"><i data-lucide="loader" style="width:24px;height:24px;animation:spin 1s linear infinite;display:block;margin:0 auto 8px"></i>Senkronize ediliyor...</div>';
    modal.classList.add('show');
    if(typeof lucide !== 'undefined') lucide.createIcons();

    try {
        const res = await fetch('/products/'+productId+'/sync', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': CSRF },
            body: JSON.stringify({ sync_type: 'all' })
        });
        const data = await res.json();

        if(data.success && data.data.results){
            let html = '';
            data.data.results.forEach(item => {
                const color = MP_COLORS[item.slug] || '#1967F2';
                const statusCls = item.status === 'success' ? 'ok' : (item.status === 'wait' ? 'wait' : 'fail');
                const statusIcon = item.status === 'success' ? '✓' : (item.status === 'wait' ? '⏳' : '✕');
                const statusText = item.status === 'success' ? 'Başarılı' : (item.status === 'wait' ? 'Bekliyor' : 'Başarısız');
                html += `<div class="sync-line">
                    <span class="mp-name" style="color:${color}">${esc(item.display_name)}</span>
                    <span class="sync-status ${statusCls}">${statusIcon} ${statusText}</span>
                    <span style="font-size:10px;color:var(--text-muted);flex:1;text-align:right">${esc(item.message)}</span>
                </div>`;
            });
            body.innerHTML = html;
            Toast.show(data.message || 'Senkronizasyon tamamlandı', 'success');
        } else {
            body.innerHTML = '<div style="color:var(--danger);text-align:center;padding:20px">'+esc(data.message || 'Hata')+'</div>';
        }
    } catch(err){
        body.innerHTML = '<div style="color:var(--danger);text-align:center;padding:20px">Bağlantı hatası: '+esc(err.message)+'</div>';
    }
}

async function retrySyncItem(productId, marketplaceId){
    try {
        const res = await fetch('/products/'+productId+'/sync', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': CSRF },
            body: JSON.stringify({ marketplace_ids: [marketplaceId], sync_type: 'all' })
        });
        const data = await res.json();
        Toast.show(data.message || 'Tekrar deneniyor...', 'success');
    } catch(err){
        Toast.show('Hata: ' + err.message, 'error');
    }
}

// ═══ GENERAL SYNC ═══
function doSync(){
    const btn = event.target.closest('button');
    const orig = btn.innerHTML;
    btn.innerHTML = '<i data-lucide="loader" style="width:14px;height:14px;animation:spin 1s linear infinite"></i> Çekiliyor...';
    btn.disabled = true;

    fetch('/products/manual-sync', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': CSRF },
        body: JSON.stringify({ marketplace_id: currentMp || 0, sync_type: 'products' })
    })
    .then(r => r.json())
    .then(d => {
        if(d.success) Toast.show(d.message || 'Senkronizasyon başlatıldı', 'success');
        else Toast.show('Hata: '+(d.message||''), 'error', 6000);
        btn.innerHTML = orig; btn.disabled = false;
        if(typeof lucide !== 'undefined') lucide.createIcons();
        table.ajax.reload();
    })
    .catch(() => { btn.innerHTML=orig; btn.disabled=false; if(typeof lucide !== 'undefined') lucide.createIcons(); table.ajax.reload(); });
}

// ═══ LIGHTBOX ═══
function openLightbox(url){
    document.getElementById('lightboxImg').src = url;
    document.getElementById('lightbox').classList.add('show');
    document.body.style.overflow = 'hidden';
}
function closeLightbox(){
    document.getElementById('lightbox').classList.remove('show');
    document.body.style.overflow = '';
}
document.addEventListener('keydown', e => { if(e.key === 'Escape') { closeLightbox(); closeSyncModal(); if(document.getElementById('prodFormOverlay').classList.contains('active')) { prodFormClose(); return; } if(document.getElementById('prodOverlay').classList.contains('active')) prodCloseWindow(); } });

// ═══ CUSTOM CONFIRM ═══
function customConfirm(msg, {title='Emin misiniz?', icon='⚠️', iconClass='warn', okText='Evet, Devam Et', okClass='', cancelText='İptal'}={}) {
    return new Promise(resolve => {
        const modal = document.getElementById('confirmModal');
        document.getElementById('confirmTitle').textContent = title;
        document.getElementById('confirmMsg').textContent = msg;
        document.getElementById('confirmIcon').textContent = icon;
        document.getElementById('confirmIcon').className = 'confirm-icon ' + iconClass;
        const okBtn = document.getElementById('confirmOk');
        okBtn.textContent = okText;
        okBtn.className = 'confirm-btn-ok ' + okClass;
        document.getElementById('confirmCancel').textContent = cancelText;
        modal.classList.add('show');
        const cleanup = (result) => { modal.classList.remove('show'); resolve(result); };
        okBtn.onclick = () => cleanup(true);
        document.getElementById('confirmCancel').onclick = () => cleanup(false);
        modal.onclick = (e) => { if(e.target === modal) cleanup(false); };
    });
}

// ═══ OVERLAY — Marketplace Products ═══
let prodActiveMpId = null;
let prodActiveMpSlug = '';
let prodActiveMpName = '';
let prodCurrentPage = 1;
let prodSearchTimer = null;

function prodOpenWindow(mpId, slug, displayName, color) {
    prodActiveMpId = mpId;
    currentMp = mpId;
    prodActiveMpSlug = slug;
    prodActiveMpName = displayName;
    prodCurrentPage = 1;

    const header = document.getElementById('prodWinHeader');
    header.style.borderBottomColor = color;
    document.getElementById('prodWinLogo').src = '/public/assets/img/marketplaces/' + slug + '.png';
    document.getElementById('prodWinTitle').textContent = 'Ürünler';
    document.getElementById('prodWinSearch').value = '';
    document.getElementById('prodOverlay').classList.add('active');
    document.body.style.overflow = 'hidden';

    prodLoadMpList(1);
}

function prodCloseWindow() {
    document.getElementById('prodOverlay').classList.remove('active');
    document.body.style.overflow = '';
    prodActiveMpId = null;
    // Refresh main table to reflect any changes
    table.ajax.reload(null, false);
}

async function prodLoadMpList(page) {
    prodCurrentPage = page || 1;
    const tbody = document.getElementById('prodWinBody');
    const search = document.getElementById('prodWinSearch').value.trim();

    tbody.innerHTML = '<tr><td colspan="8" style="text-align:center;padding:60px;color:var(--text-muted)"><div style="animation:spin 1s linear infinite;display:inline-block;margin-bottom:8px">⏳</div><br>Yükleniyor...</td></tr>';

    const params = new URLSearchParams({ marketplace_id: prodActiveMpId, page: prodCurrentPage });
    if (search) params.set('search', search);

    try {
        const res = await fetch('/products/mp-products?' + params);
        const json = await res.json();
        if (!json.success) { tbody.innerHTML = `<tr><td colspan="8" style="text-align:center;padding:40px;color:var(--danger)">${esc(json.message || 'Hata')}</td></tr>`; return; }

        const items = json.data || [];
        document.getElementById('prodWinInfo').textContent = `Toplam ${json.total} ürün · Sayfa ${json.page}/${json.pages || 1}`;

        if (items.length === 0) {
            tbody.innerHTML = '<tr><td colspan="8" style="text-align:center;padding:60px;color:var(--text-muted)"><div style="font-size:48px;margin-bottom:12px">📦</div>Bu pazaryerinde ürün bulunamadı</td></tr>';
            document.getElementById('prodWinPagination').innerHTML = '';
            return;
        }

        let html = '';
        items.forEach(p => {
            const hasMatch = p.product_id && parseInt(p.product_id) > 0;
            const img = p.main_image
                ? `<img src="${esc(p.main_image)}" style="width:40px;height:40px;border-radius:8px;object-fit:cover;border:1px solid var(--border)" loading="lazy" onerror="this.outerHTML='<div style=\\'width:40px;height:40px;border-radius:8px;background:var(--bg-input);display:flex;align-items:center;justify-content:center;border:1px dashed var(--border);font-size:14px\\'>📷</div>'">`
                : '<div style="width:40px;height:40px;border-radius:8px;background:var(--bg-input);display:flex;align-items:center;justify-content:center;border:1px dashed var(--border);font-size:14px">📷</div>';
            const matchBadge = hasMatch
                ? `<span class="mp-status matched">✅ Eşleşik #${p.product_id}</span>`
                : `<span class="mp-status unmatched">⚠️ Eşleşmemiş</span>`;
            const syncErr = p.sync_error ? `<div style="font-size:10px;color:var(--danger);margin-top:2px" title="${esc(p.sync_error)}">⚠ ${esc(p.sync_error).substring(0,40)}</div>` : '';
            const price = parseFloat(p.last_price_sent || p.system_price || 0);
            const stock = parseInt(p.last_stock_sent ?? p.system_stock ?? 0);
            const detailLink = hasMatch
                ? `<a href="/products/${p.product_id}" class="o-ra" style="font-size:11px"><i data-lucide="eye" style="width:12px;height:12px"></i> Detay</a>`
                : `<button class="o-ra o-ra-sync" onclick="addMpProductToSystem(${p.id})" style="font-size:11px"><i data-lucide="plus" style="width:12px;height:12px"></i> Ekle</button>`;

            html += `<tr>
                <td>${img}</td>
                <td><div style="min-width:0">
                    <div style="font-weight:700;font-size:13px;max-width:260px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap" title="${esc(p.product_name || '')}">${esc(p.product_name || 'İsimsiz')}</div>
                    ${syncErr}
                </div></td>
                <td><code style="font-size:11px;background:var(--bg-input);padding:2px 6px;border-radius:4px">${esc(p.marketplace_sku || '-')}</code></td>
                <td style="font-size:12px;font-family:monospace">${esc(p.barcode || '-')}</td>
                <td style="text-align:center;font-weight:700">${price > 0 ? fmtMoney(price) : '<span style="color:var(--text-muted)">—</span>'}</td>
                <td style="text-align:center"><span class="badge ${stock <= 0 ? 'badge-danger' : 'badge-success'}" style="padding:3px 10px">${stock}</span></td>
                <td>${matchBadge}</td>
                <td><div class="o-row-actions">${detailLink}</div></td>
            </tr>`;
        });
        tbody.innerHTML = html;

        // Pagination
        prodRenderPagination(json.page, json.pages || 1, json.total);

        if (typeof lucide !== 'undefined') lucide.createIcons();
    } catch(e) {
        tbody.innerHTML = `<tr><td colspan="8" style="text-align:center;padding:40px;color:var(--danger)">Bağlantı hatası: ${esc(e.message)}</td></tr>`;
    }
}

function prodRenderPagination(current, total, totalItems) {
    const el = document.getElementById('prodWinPagination');
    if (total <= 1) { el.innerHTML = ''; return; }
    let h = '';
    h += `<button ${current<=1?'disabled':''} onclick="prodLoadMpList(${current-1})">←</button>`;
    const start = Math.max(1, current - 3);
    const end = Math.min(total, current + 3);
    if (start > 1) { h += `<button onclick="prodLoadMpList(1)">1</button>`; if(start > 2) h += '<span style="color:var(--text-muted);padding:0 4px">…</span>'; }
    for (let i = start; i <= end; i++) {
        h += `<button class="${i===current?'active':''}" onclick="prodLoadMpList(${i})">${i}</button>`;
    }
    if (end < total) { if(end < total-1) h += '<span style="color:var(--text-muted);padding:0 4px">…</span>'; h += `<button onclick="prodLoadMpList(${total})">${total}</button>`; }
    h += `<button ${current>=total?'disabled':''} onclick="prodLoadMpList(${current+1})">→</button>`;
    el.innerHTML = h;
}

// Overlay search
document.getElementById('prodWinSearch').addEventListener('input', function(){
    clearTimeout(prodSearchTimer);
    prodSearchTimer = setTimeout(() => prodLoadMpList(1), 400);
});
document.getElementById('prodWinSearch').addEventListener('keydown', function(e){
    if(e.key === 'Enter') { e.preventDefault(); clearTimeout(prodSearchTimer); prodLoadMpList(1); }
});

// Overlay close: ESC + backdrop
document.getElementById('prodOverlay').addEventListener('click', function(e){
    if(e.target === this) prodCloseWindow();
});

// Auto-match from overlay
async function autoMatchOverlay() {
    if (!prodActiveMpId) return;
    const ok = await customConfirm(`${prodActiveMpName} ürünlerini barkod/SKU ile otomatik eşleştirmek istiyor musunuz?`, {title:'Otomatik Eşleştirme', icon:'⚡', iconClass:'info', okText:'Eşleştir', okClass:'success'});
    if (!ok) return;

    const box = document.getElementById('pSyncBox');
    const bar = document.getElementById('pSyncBarFill');
    const title = document.getElementById('pSyncTitle');
    const status = document.getElementById('pSyncStatus');
    box.classList.add('show');
    bar.style.width = '30%';
    bar.style.background = 'linear-gradient(90deg,#10b981,#059669)';
    title.textContent = `${prodActiveMpName} ürünleri eşleştiriliyor...`;
    status.textContent = 'Barkod ve SKU karşılaştırılıyor...';

    const fd = new FormData();
    fd.append('marketplace_id', prodActiveMpId);
    fd.append('_csrf_token', CSRF);

    try {
        bar.style.width = '60%';
        const res = await fetch('/products/auto-match', {method:'POST', body:fd});
        const data = await res.json();
        bar.style.width = '100%';
        if (data.success) {
            title.textContent = '✅ Eşleştirme Tamamlandı!';
            status.textContent = data.data?.message || 'Tamamlandı';
            Toast.show(data.data?.message || 'Eşleştirme tamamlandı', 'success');
            prodLoadMpList(1);
            setTimeout(() => box.classList.remove('show'), 3000);
        } else {
            title.textContent = '❌ Hata';
            status.textContent = data.message;
            bar.style.background = '#ef4444';
        }
    } catch(e) {
        title.textContent = '❌ Bağlantı Hatası';
        status.textContent = e.message;
        bar.style.background = '#ef4444';
    }
}

// Sync from overlay
async function syncOverlay() {
    if (!prodActiveMpId) return;
    const ok = await customConfirm(`${prodActiveMpName}'dan ürünleri çekmek istiyor musunuz?`, {title:'Ürünleri Çek', icon:'📥', iconClass:'info', okText:'Çek/Güncelle', okClass:'primary'});
    if (!ok) return;

    const box = document.getElementById('pSyncBox');
    const bar = document.getElementById('pSyncBarFill');
    const title = document.getElementById('pSyncTitle');
    const status = document.getElementById('pSyncStatus');
    box.classList.add('show');
    bar.style.width = '30%';
    bar.style.background = 'linear-gradient(90deg,#1967F2,#4B8AF5)';
    title.textContent = `${prodActiveMpName}'dan ürünler çekiliyor...`;
    status.textContent = 'API bağlantısı kuruluyor...';

    try {
        bar.style.width = '60%';
        status.textContent = 'Ürünler alınıyor...';
        const res = await fetch('/products/manual-sync', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': CSRF },
            body: JSON.stringify({ marketplace_id: prodActiveMpId, sync_type: 'products' })
        });
        const data = await res.json();
        bar.style.width = '100%';
        if (data.success) {
            title.textContent = '✅ Tamamlandı!';
            status.textContent = data.message || 'Ürünler çekildi';
            Toast.show(data.message || 'Senkronizasyon tamamlandı', 'success');
            prodLoadMpList(1);
            setTimeout(() => box.classList.remove('show'), 2000);
        } else {
            title.textContent = '❌ Hata';
            status.textContent = data.message;
            bar.style.background = '#ef4444';
        }
    } catch(e) {
        title.textContent = '❌ Bağlantı Hatası';
        status.textContent = e.message;
        bar.style.background = '#ef4444';
    }
}

// Add to system (from overlay)
async function addMpProductToSystem(mpProductId) {
    const ok = await customConfirm('Bu ürünü sisteme eklemek istiyor musunuz?', {title:'Sisteme Ekle', icon:'📦', iconClass:'info', okText:'Ekle', okClass:'success'});
    if (!ok) return;

    const fd = new FormData();
    fd.append('mp_product_id', mpProductId);
    fd.append('_csrf_token', CSRF);

    try {
        const res = await fetch('/products/add-to-system', {method:'POST', body:fd});
        const data = await res.json();
        if (data.success) {
            Toast.show(data.data?.message || 'Ürün sisteme eklendi', 'success');
            prodLoadMpList(prodCurrentPage);
        } else {
            Toast.show(data.message || 'Hata', 'error');
        }
    } catch(e) {
        Toast.show('Bağlantı hatası: ' + e.message, 'error');
    }
}

// ═══ PRODUCT FORM OVERLAY ═══
let pfoEditId = null;
let pfoQuillDesc = null;
let pfoQuillShortDesc = null;
let pfoImages = [];
let pfoVariantCount = 0;
let pfoAttributes = [];
let pfoProductAttributes = [];

function pfoSwitchTab(tabId) {
    document.querySelectorAll('.pfo-tab').forEach(t => t.classList.toggle('active', t.dataset.tab === tabId));
    document.querySelectorAll('.pfo-panel').forEach(p => p.classList.toggle('active', p.id === 'pfoPanel-'+tabId));
    // Initialize Quill editors when images tab is shown
    if (tabId === 'images') pfoInitQuill();
    if (typeof lucide !== 'undefined') lucide.createIcons();
}

function pfoInitQuill() {
    if (pfoQuillDesc) return; // Already initialized
    const toolbarOpts = [
        [{ 'header': [1, 2, 3, false] }],
        ['bold', 'italic', 'underline', 'strike'],
        [{ 'color': [] }, { 'background': [] }],
        [{ 'list': 'ordered' }, { 'list': 'bullet' }],
        [{ 'align': [] }],
        ['link', 'image'],
        ['clean']
    ];
    pfoQuillDesc = new Quill('#pfoDescEditor', { theme: 'snow', modules: { toolbar: toolbarOpts }, placeholder: 'Ürün açıklamasını buraya yazın...' });
    pfoQuillShortDesc = new Quill('#pfoShortDescEditor', { theme: 'snow', modules: { toolbar: [[{ 'header': [3, false] }], ['bold', 'italic', 'underline'], [{ 'list': 'ordered' }, { 'list': 'bullet' }], ['clean']] }, placeholder: 'Kısa açıklama...' });
}

function pfoDestroyQuill() {
    if (pfoQuillDesc) { document.getElementById('pfoDescEditor').innerHTML = ''; pfoQuillDesc = null; }
    if (pfoQuillShortDesc) { document.getElementById('pfoShortDescEditor').innerHTML = ''; pfoQuillShortDesc = null; }
    // Re-create empty containers
    const descWrap = document.getElementById('pfoDescWrap');
    const shortWrap = document.getElementById('pfoShortDescWrap');
    if (descWrap) { descWrap.innerHTML = '<div id="pfoDescEditor"></div>'; }
    if (shortWrap) { shortWrap.innerHTML = '<div id="pfoShortDescEditor"></div>'; }
}

async function prodFormOpen(productId) {
    pfoEditId = productId;
    pfoImages = [];
    pfoVariantCount = 0;
    pfoAttributes = [];
    pfoProductAttributes = [];
    pfoMpData = null;

    // Destroy any old Quill instances
    pfoDestroyQuill();

    // Reset all fields
    pfoResetForm();

    // Show/hide sidebar based on mode (edit = show, create = hide)
    const sidebar = document.getElementById('pfoSidebar');
    sidebar.classList.toggle('active', !!productId);

    // Show overlay
    document.getElementById('prodFormOverlay').classList.add('active');
    document.body.style.overflow = 'hidden';
    pfoSwitchTab('general');

    if (productId) {
        // Edit mode
        document.querySelector('#pfoTitle span').textContent = 'Ürün Düzenle';
        document.querySelector('#pfoTitle [data-lucide]').setAttribute('data-lucide', 'edit-3');
        document.getElementById('pfoSaveBtn').querySelector('span').textContent = 'Güncelle';
        document.getElementById('pfoSaveBtnText2').textContent = 'Güncelle';
        document.getElementById('pfoSku').readOnly = true;
        document.getElementById('pfoSku').style.opacity = '0.6';
        document.getElementById('pfoFooterInfo').textContent = 'Yükleniyor...';

        try {
            const res = await fetch('/products/'+productId+'/edit', {
                headers: { 'X-Requested-With': 'XMLHttpRequest' }
            });
            const json = await res.json();
            if (!json.success) { Toast.show(json.message || 'Hata', 'error'); prodFormClose(); return; }
            pfoFillForm(json.data);
            document.getElementById('pfoFooterInfo').textContent = 'Ürün #'+productId+' · Son güncelleme: '+(json.data.product?.updated_at || '—');
            // Load marketplace settings in parallel
            pfoLoadMarketplaces(productId);
        } catch(e) {
            Toast.show('Bağlantı hatası: '+e.message, 'error');
            prodFormClose();
        }
    } else {
        // Create mode
        document.querySelector('#pfoTitle span').textContent = 'Yeni Ürün Ekle';
        document.querySelector('#pfoTitle [data-lucide]').setAttribute('data-lucide', 'plus');
        document.getElementById('pfoSaveBtn').querySelector('span').textContent = 'Oluştur';
        document.getElementById('pfoSaveBtnText2').textContent = 'Oluştur';
        document.getElementById('pfoSku').readOnly = false;
        document.getElementById('pfoSku').style.opacity = '1';
        document.getElementById('pfoFooterInfo').textContent = '';

        // Load form metadata (categories, brands, attributes) for create mode
        try {
            const res = await fetch('/products/create', {
                headers: { 'X-Requested-With': 'XMLHttpRequest' }
            });
            const json = await res.json();
            if (json.success && json.data) {
                pfoRenderAttributes(json.data.attributes || [], []);
            }
        } catch(e) { /* silently continue */ }
    }

    if (typeof lucide !== 'undefined') lucide.createIcons();
}

function prodFormClose() {
    document.getElementById('prodFormOverlay').classList.remove('active');
    document.body.style.overflow = '';
    pfoEditId = null;
    pfoDestroyQuill();
}

function pfoResetForm() {
    document.getElementById('pfoProductId').value = '';
    document.getElementById('pfoName').value = '';
    document.getElementById('pfoSku').value = '';
    document.getElementById('pfoBarcode').value = '';
    document.getElementById('pfoCategory').value = '';
    document.getElementById('pfoBrand').value = '';
    document.getElementById('pfoBasePrice').value = '';
    document.getElementById('pfoListPrice').value = '';
    document.getElementById('pfoCostPrice').value = '';
    document.getElementById('pfoTaxRate').value = '20';
    document.getElementById('pfoStockQty').value = '0';
    document.getElementById('pfoMinStock').value = '5';
    document.getElementById('pfoWeight').value = '';
    document.getElementById('pfoDesi').value = '';
    document.getElementById('pfoUnit').value = 'adet';
    document.getElementById('pfoIsActive').checked = true;
    document.getElementById('pfoSeoTitle').value = '';
    document.getElementById('pfoSeoDesc').value = '';
    document.getElementById('pfoStockQty').readOnly = false;
    document.getElementById('pfoStockQty').style.opacity = '1';

    // Clear images
    pfoImages = [];
    pfoRenderImageGallery();

    // Clear variants
    const vList = document.getElementById('pfoVariantsList');
    vList.querySelectorAll('.pfo-vr-row:not(.pfo-vr-header)').forEach(r => r.remove());
    pfoVariantCount = 0;

    // Clear attrs
    document.getElementById('pfoAttrsContainer').innerHTML = '<div style="text-align:center;padding:20px;color:var(--text-muted);font-size:13px">Özellikler yüklenecek</div>';

    // Clear marketplace
    document.getElementById('pfoMpContainer').innerHTML = '<div style="text-align:center;padding:32px;color:var(--text-muted);font-size:13px"><i data-lucide="loader" style="width:20px;height:20px;animation:spin 1s linear infinite;display:inline-block;vertical-align:middle;margin-right:6px"></i> Yükleniyor...</div>';
    pfoMpData = null;

    pfoUpdateSeoPreview();
}

function pfoFillForm(data) {
    const p = data.product;
    if (!p) return;

    document.getElementById('pfoProductId').value = p.id;
    document.getElementById('pfoName').value = p.name || '';
    document.getElementById('pfoSku').value = p.sku || '';
    document.getElementById('pfoBarcode').value = p.barcode || '';
    document.getElementById('pfoCategory').value = p.category_id || '';
    document.getElementById('pfoBrand').value = p.brand_id || '';
    document.getElementById('pfoBasePrice').value = p.base_price || '';
    document.getElementById('pfoListPrice').value = p.list_price || '';
    document.getElementById('pfoCostPrice').value = p.cost_price || '';
    document.getElementById('pfoTaxRate').value = p.tax_rate ?? '20';
    document.getElementById('pfoStockQty').value = p.stock_qty ?? 0;
    document.getElementById('pfoMinStock').value = p.min_stock_alert ?? 5;
    document.getElementById('pfoWeight').value = p.weight || '';
    document.getElementById('pfoDesi').value = p.desi || '';
    document.getElementById('pfoUnit').value = p.unit || 'adet';
    document.getElementById('pfoIsActive').checked = parseInt(p.is_active) === 1;
    document.getElementById('pfoSeoTitle').value = p.seo_title || '';
    document.getElementById('pfoSeoDesc').value = p.seo_description || '';

    // Edit mode: stock readonly
    document.getElementById('pfoStockQty').readOnly = true;
    document.getElementById('pfoStockQty').style.opacity = '0.6';

    // Images
    pfoImages = [];
    if (p.images_json) {
        try {
            const imgs = JSON.parse(p.images_json);
            if (Array.isArray(imgs)) {
                pfoImages = imgs.map(img => typeof img === 'string' ? { url: img } : img).filter(img => img.url);
            }
        } catch(e) {}
    }
    pfoRenderImageGallery();

    // Description — will be set when Quill is initialized
    pfoDescContent = p.description || '';
    pfoShortDescContent = p.short_description || '';

    // Attributes
    pfoRenderAttributes(data.attributes || [], data.productAttributes || []);

    // Load existing variants
    const variantsList = data.variants || [];
    if (variantsList.length > 0) {
        variantsList.forEach(v => {
            pfoAddVariant({
                name: v.name || v.variant_label || '',
                sku: v.sku || '',
                barcode: v.barcode || '',
                price: v.base_price || '',
                stock: v.stock_qty ?? 0
            });
        });
    }

    pfoUpdateSeoPreview();
}

let pfoDescContent = '';
let pfoShortDescContent = '';

// ═══ IMAGE MANAGEMENT ═══
function pfoRenderImageGallery() {
    const g = document.getElementById('pfoImageGallery');
    let html = '';
    pfoImages.forEach((img, i) => {
        html += `<div class="pfo-img" data-idx="${i}">
            ${i === 0 ? '<span class="badge">ANA</span>' : ''}
            <img src="${esc(img.url)}" alt="" loading="lazy" onclick="event.stopPropagation();openLightbox('${esc(img.url)}')">
            <div class="acts">
                <button type="button" class="del" onclick="event.stopPropagation();pfoRemoveImage(${i})" title="Sil">✖</button>
                ${i > 0 ? '<button type="button" class="star" onclick="event.stopPropagation();pfoMakeMainImage('+i+')" title="Ana görsel yap">★</button>' : ''}
            </div>
        </div>`;
    });
    html += `<div class="pfo-img-add" onclick="document.getElementById('pfoFileInput').click()"><i data-lucide="plus"></i> Ekle</div>`;
    g.innerHTML = html;
    if (typeof lucide !== 'undefined') lucide.createIcons();
}

function pfoRemoveImage(idx) {
    pfoImages.splice(idx, 1);
    pfoRenderImageGallery();
    Toast.show('Görsel kaldırıldı', 'success', 1500);
}

function pfoMakeMainImage(idx) {
    const img = pfoImages.splice(idx, 1)[0];
    pfoImages.unshift(img);
    pfoRenderImageGallery();
    Toast.show('Ana görsel değiştirildi', 'success', 1500);
}

function pfoAddImageFromUrl() {
    const inp = document.getElementById('pfoNewImageUrl');
    const url = inp.value.trim();
    if (!url || !url.match(/^https?:\/\//i)) { Toast.show('Geçerli URL girin', 'warning'); return; }
    pfoImages.push({ url: url });
    pfoRenderImageGallery();
    inp.value = '';
    Toast.show('Görsel eklendi', 'success', 1500);
}

function pfoHandleFileUpload(files) {
    if (!files) return;
    Array.from(files).forEach(f => {
        if (!f.type.startsWith('image/')) return;
        if (f.size > 5*1024*1024) { Toast.show(f.name + ' çok büyük (max 5MB)', 'warning'); return; }
        const fd = new FormData();
        fd.append('image', f);
        fetch('/products/upload-image', { method: 'POST', headers: { 'X-CSRF-Token': CSRF }, body: fd })
        .then(r => r.json()).then(d => {
            if (d.success && d.data?.url) {
                pfoImages.push({ url: d.data.url });
                pfoRenderImageGallery();
                Toast.show('Yüklendi: ' + (d.data.filename || ''), 'success', 1500);
            } else { Toast.show(d.message || 'Yükleme hatası', 'error'); }
        }).catch(e => Toast.show('Hata: ' + e.message, 'error'));
    });
    document.getElementById('pfoFileInput').value = '';
}

// Drag-drop
(function(){
    const dz = document.getElementById('pfoDropZone');
    if (!dz) return;
    ['dragenter','dragover'].forEach(e => dz.addEventListener(e, ev => { ev.preventDefault(); dz.classList.add('dragover'); }));
    ['dragleave','drop'].forEach(e => dz.addEventListener(e, ev => { ev.preventDefault(); dz.classList.remove('dragover'); }));
    dz.addEventListener('drop', ev => { ev.preventDefault(); pfoHandleFileUpload(ev.dataTransfer.files); });
})();

// ═══ VARIANTS ═══
function pfoAddVariant(data) {
    pfoVariantCount++;
    const row = document.createElement('div');
    row.className = 'pfo-vr-row';
    row.innerHTML = `
        <input type="text" name="pfo_v_name_${pfoVariantCount}" value="${esc(data?.name || '')}" placeholder="Kırmızı - XL">
        <input type="text" name="pfo_v_sku_${pfoVariantCount}" value="${esc(data?.sku || '')}" placeholder="SKU">
        <input type="text" name="pfo_v_barcode_${pfoVariantCount}" value="${esc(data?.barcode || '')}" placeholder="Barkod">
        <input type="number" name="pfo_v_price_${pfoVariantCount}" step="0.01" value="${data?.price || ''}" placeholder="0.00">
        <input type="number" name="pfo_v_stock_${pfoVariantCount}" value="${data?.stock ?? 0}">
        <button type="button" class="pfo-vr-del" onclick="this.closest('.pfo-vr-row').remove()"><i data-lucide="x" style="width:14px;height:14px"></i></button>
    `;
    document.getElementById('pfoVariantsList').appendChild(row);
    if (typeof lucide !== 'undefined') lucide.createIcons();
    if (!data) row.querySelector('input').focus();
}

// ═══ ATTRIBUTES ═══
function pfoRenderAttributes(attributes, productAttributes) {
    pfoAttributes = attributes;
    pfoProductAttributes = productAttributes;
    const container = document.getElementById('pfoAttrsContainer');

    if (!attributes || attributes.length === 0) {
        container.innerHTML = '<div style="text-align:center;padding:20px;color:var(--text-muted);font-size:13px"><i data-lucide="info" style="width:16px;height:16px;display:inline-block;vertical-align:middle;margin-right:4px"></i> Henüz özellik tanımlanmamış</div>';
        if (typeof lucide !== 'undefined') lucide.createIcons();
        return;
    }

    let html = '<div class="pfo-row pfo-r3">';
    attributes.forEach((attr, i) => {
        let currentValue = '';
        let currentValueId = '';
        productAttributes.forEach(pav => {
            if (parseInt(pav.attribute_id) === parseInt(attr.id)) {
                currentValue = pav.custom_value || pav.attribute_value_text || '';
                currentValueId = pav.attribute_value_id || '';
            }
        });

        html += '<div class="pfo-f">';
        html += '<label>' + esc(attr.name) + (parseInt(attr.is_required) ? ' *' : '') + '</label>';

        if (['select','multi_select'].includes(attr.input_type) && attr.values && attr.values.length > 0) {
            html += '<select name="pfo_attr_'+attr.id+'" data-attr-id="'+attr.id+'">';
            html += '<option value="">—</option>';
            attr.values.forEach(v => {
                const sel = (currentValue == v.value || currentValueId == v.id) ? 'selected' : '';
                html += '<option value="'+v.id+'" '+sel+'>'+esc(v.value)+'</option>';
            });
            html += '</select>';
        } else if (attr.input_type === 'boolean') {
            html += '<select name="pfo_attr_'+attr.id+'" data-attr-id="'+attr.id+'">';
            html += '<option value="">—</option>';
            html += '<option value="Evet"'+(currentValue==='Evet'?' selected':'')+'>Evet</option>';
            html += '<option value="Hayır"'+(currentValue==='Hayır'?' selected':'')+'>Hayır</option>';
            html += '</select>';
        } else if (attr.input_type === 'number') {
            html += '<input type="number" name="pfo_attr_'+attr.id+'" data-attr-id="'+attr.id+'" step="0.01" value="'+esc(currentValue)+'">';
        } else {
            html += '<input type="text" name="pfo_attr_'+attr.id+'" data-attr-id="'+attr.id+'" value="'+esc(currentValue)+'">';
        }

        html += '</div>';
        if ((i + 1) % 3 === 0 && i < attributes.length - 1) {
            html += '</div><div class="pfo-row pfo-r3">';
        }
    });
    html += '</div>';
    container.innerHTML = html;
}

// ═══ MARKETPLACE SETTINGS ═══
let pfoMpData = null; // Cached MP data

async function pfoLoadMarketplaces(productId) {
    const container = document.getElementById('pfoMpContainer');
    container.innerHTML = '<div style="text-align:center;padding:32px;color:var(--text-muted);font-size:13px"><i data-lucide="loader" style="width:20px;height:20px;animation:spin 1s linear infinite;display:inline-block;vertical-align:middle;margin-right:6px"></i> Yükleniyor...</div>';
    if (typeof lucide !== 'undefined') lucide.createIcons();

    try {
        const res = await fetch('/products/'+productId+'/marketplace-settings', {
            headers: { 'X-Requested-With': 'XMLHttpRequest' }
        });
        const json = await res.json();
        if (!json.success) { container.innerHTML = '<div style="padding:20px;color:var(--danger);text-align:center">'+esc(json.message || 'Hata')+'</div>'; return; }
        pfoMpData = json.data;
        pfoRenderMpCards(json.data);
    } catch(e) {
        container.innerHTML = '<div style="padding:20px;color:var(--danger);text-align:center">Bağlantı hatası: '+esc(e.message)+'</div>';
    }
}

function pfoRenderMpCards(data) {
    const container = document.getElementById('pfoMpContainer');
    const mps = data.marketplaces || [];

    if (mps.length === 0) {
        container.innerHTML = '<div style="padding:24px;text-align:center;color:var(--text-muted);font-size:13px"><i data-lucide="store" style="width:20px;height:20px;display:inline-block;vertical-align:middle;margin-right:6px"></i> Aktif pazaryeri bulunamadı. <a href="/marketplaces" style="color:var(--primary)">Pazaryeri ayarlarına git →</a></div>';
        if (typeof lucide !== 'undefined') lucide.createIcons();
        return;
    }

    let html = '';
    mps.forEach(mp => {
        const enabled = mp.is_enabled;
        const listed = mp.is_listed;
        const catOk = mp.category_mapped;
        const brandOk = mp.brand_mapped;
        const hasCat = mp.has_category;
        const hasBrand = mp.has_brand;

        // Status badges
        let statusBadges = '';
        if (listed) statusBadges += '<span class="pfo-mp-badge ok">🟢 Yayında</span>';
        else if (enabled) statusBadges += '<span class="pfo-mp-badge info">⏳ Hazır</span>';
        if (mp.sync_error) statusBadges += '<span class="pfo-mp-badge err">⚠ Sync Hatası</span>';

        // Price rule display
        const priceRuleTypes = {
            'none': 'Kural Yok', 'percent_increase': '% Artış', 'percent_decrease': '% Azalış',
            'fixed_increase': 'Sabit Artış', 'fixed_decrease': 'Sabit Azalış'
        };

        // Calculated price
        const calcPrice = mp.calculated_price?.sale_price || data.base_price;
        const calcList = mp.calculated_price?.list_price || data.list_price || calcPrice;
        const computedStock = mp.computed_stock ?? data.stock_qty;

        html += `<div class="pfo-mp-card ${enabled ? 'enabled' : ''}" data-mp-id="${mp.marketplace_id}" id="pfoMpCard-${mp.marketplace_id}">
          <div class="pfo-mp-card-head" onclick="pfoToggleMp(${mp.marketplace_id})">
            <div class="pfo-mp-icon" style="background:${esc(mp.marketplace_color)}15;border:1px solid ${esc(mp.marketplace_color)}30"><img src="/public/assets/img/marketplaces/${esc(mp.marketplace_slug)}.png" alt="${esc(mp.marketplace_name)}" style="width:30px;height:30px;object-fit:contain" onerror="this.style.display='none';this.parentNode.textContent='${esc(mp.marketplace_icon)}'"></div>
            <div class="pfo-mp-info">
              <div class="pfo-mp-name">${esc(mp.marketplace_name)}</div>
              <div class="pfo-mp-status">${statusBadges}${mp.mp_sku ? '<span style="font-size:10px;color:var(--text-muted)">SKU: '+esc(mp.mp_sku)+'</span>' : ''}</div>
            </div>
            <label style="display:inline-flex;align-items:center;gap:6px;cursor:pointer" onclick="event.stopPropagation()">
              <span class="pfo-toggle"><input type="checkbox" class="pfo-mp-toggle" data-mp-id="${mp.marketplace_id}" ${enabled ? 'checked' : ''} onchange="pfoToggleMpCheckbox(${mp.marketplace_id}, this.checked)"><span class="pfo-toggle-slider"></span></span>
            </label>
          </div>
          <div class="pfo-mp-body">`;

        // Matching warnings
        if (!hasCat) {
            html += '<div class="pfo-mp-match-warn"><span>⚠️</span> <span>Ürüne kategori atanmamış. Pazaryerine göndermek için önce <strong>Genel</strong> sekmesinden kategori seçin.</span></div>';
        } else if (!catOk) {
            html += '<div class="pfo-mp-match-warn"><span>⚠️</span> <span>Bu kategori <strong>'+esc(mp.marketplace_name)+'</strong> ile eşleştirilmemiş. <a href="/categories" target="_blank" style="color:#92400e;font-weight:700;text-decoration:underline">Kategori eşleştirmesine git →</a></span></div>';
        }
        if (hasBrand && !brandOk) {
            html += '<div class="pfo-mp-match-warn"><span>⚠️</span> <span>Ürünün markası <strong>'+esc(mp.marketplace_name)+'</strong> ile eşleştirilmemiş. <a href="/brands" target="_blank" style="color:#92400e;font-weight:700;text-decoration:underline">Marka eşleştirmesine git →</a></span></div>';
        }

        // Matched info
        if (catOk) {
            html += '<div style="margin-top:8px;font-size:11px;color:var(--text-secondary)">✅ Kategori: <strong>'+esc(mp.category_mp_path || mp.category_mp_name || '-')+'</strong></div>';
        }
        if (brandOk) {
            html += '<div style="margin-top:2px;font-size:11px;color:var(--text-secondary)">✅ Marka: <strong>'+esc(mp.brand_mp_name || '-')+'</strong></div>';
        }

        // Settings fields
        html += `<div class="pfo-mp-grid">
              <div class="pfo-mp-field">
                <label>Komisyon Tipi</label>
                <select class="pfo-mp-prt" data-mp-id="${mp.marketplace_id}" onchange="pfoCalcPreview(${mp.marketplace_id})">
                  <option value="none" ${mp.price_rule_type==='none'?'selected':''}>Kural Yok</option>
                  <option value="percent_increase" ${mp.price_rule_type==='percent_increase'?'selected':''}>% Artış</option>
                  <option value="percent_decrease" ${mp.price_rule_type==='percent_decrease'?'selected':''}>% Azalış</option>
                  <option value="fixed_increase" ${mp.price_rule_type==='fixed_increase'?'selected':''}>Sabit Artış (₺)</option>
                  <option value="fixed_decrease" ${mp.price_rule_type==='fixed_decrease'?'selected':''}>Sabit Azalış (₺)</option>
                </select>
              </div>
              <div class="pfo-mp-field">
                <label>Komisyon Değeri</label>
                <input type="number" step="0.01" min="0" class="pfo-mp-prv" data-mp-id="${mp.marketplace_id}" value="${mp.price_rule_value || ''}" placeholder="0" oninput="pfoCalcPreview(${mp.marketplace_id})">
              </div>
              <div class="pfo-mp-field">
                <label>Stok Farkı</label>
                <input type="number" min="0" step="1" class="pfo-mp-so" data-mp-id="${mp.marketplace_id}" value="${mp.stock_offset || 0}" placeholder="0" oninput="pfoCalcPreview(${mp.marketplace_id})">
              </div>
              <div class="pfo-mp-field">
                <label>MP SKU</label>
                <input type="text" value="${esc(mp.mp_sku || '')}" readonly style="opacity:.6;cursor:default" title="Pazaryeri tarafında atanan SKU">
              </div>
            </div>`;

        // Preview
        html += `<div class="pfo-mp-preview">
              <div class="pfo-mp-prev-item"><span class="pfo-mp-prev-label">Gönderilecek Fiyat</span><span class="pfo-mp-prev-val price" id="pfoMpPrice-${mp.marketplace_id}">${formatMoney(calcPrice)}</span></div>
              <div class="pfo-mp-prev-item"><span class="pfo-mp-prev-label">Liste Fiyatı</span><span class="pfo-mp-prev-val" id="pfoMpList-${mp.marketplace_id}">${formatMoney(calcList)}</span></div>
              <div class="pfo-mp-prev-item"><span class="pfo-mp-prev-label">Gönderilecek Stok</span><span class="pfo-mp-prev-val" id="pfoMpStock-${mp.marketplace_id}">${computedStock}</span></div>
              <div class="pfo-mp-prev-item"><span class="pfo-mp-prev-label">Son Fiyat / Stok</span><span class="pfo-mp-prev-val" style="font-size:11px">${mp.last_price_sent ? formatMoney(mp.last_price_sent) : '—'} / ${mp.last_stock_sent ?? '—'}</span></div>
            </div>`;

        // Action buttons
        const canPublish = hasCat && catOk && (!hasBrand || brandOk);
        html += `<div class="pfo-mp-actions">
              <button type="button" class="btn-publish" onclick="pfoPublishMp(${mp.marketplace_id})" ${canPublish ? '' : 'disabled title="Kategori/marka eşleştirmesi eksik"'}><i data-lucide="rocket" style="width:12px;height:12px"></i> ${listed ? 'Güncelle' : 'Yayınla'}</button>
              <button type="button" onclick="pfoSyncMp(${mp.marketplace_id},'all')"><i data-lucide="refresh-cw" style="width:12px;height:12px"></i> Sync</button>
              <button type="button" onclick="pfoSyncMp(${mp.marketplace_id},'price')"><i data-lucide="tag" style="width:12px;height:12px"></i> Fiyat</button>
              <button type="button" onclick="pfoSyncMp(${mp.marketplace_id},'stock')"><i data-lucide="package" style="width:12px;height:12px"></i> Stok</button>
            </div>`;

        // Sync info
        if (mp.last_synced_at) {
            html += `<div class="pfo-mp-sync-info"><span>Son sync: ${esc(mp.last_synced_at)}</span></div>`;
        }
        if (mp.sync_error) {
            html += `<div class="pfo-mp-sync-err">⚠ ${esc(mp.sync_error)}</div>`;
        }

        html += '</div></div>';
    });

    container.innerHTML = html;
    if (typeof lucide !== 'undefined') lucide.createIcons();
}

function pfoToggleMp(mpId) {
    const cb = document.querySelector('.pfo-mp-toggle[data-mp-id="'+mpId+'"]');
    if (cb) {
        cb.checked = !cb.checked;
        pfoToggleMpCheckbox(mpId, cb.checked);
    }
}

function pfoToggleMpCheckbox(mpId, checked) {
    const card = document.getElementById('pfoMpCard-'+mpId);
    if (card) card.classList.toggle('enabled', checked);
}

function pfoCalcPreview(mpId) {
    if (!pfoMpData) return;
    // Read live values from Genel tab inputs
    const basePrice = parseFloat(document.getElementById('pfoBasePrice').value) || pfoMpData.base_price || 0;
    const listPrice = parseFloat(document.getElementById('pfoListPrice').value) || basePrice;
    const stockQty = parseInt(document.getElementById('pfoStockQty').value) || pfoMpData.stock_qty || 0;

    const typeEl = document.querySelector('.pfo-mp-prt[data-mp-id="'+mpId+'"]');
    const valEl = document.querySelector('.pfo-mp-prv[data-mp-id="'+mpId+'"]');
    const soEl = document.querySelector('.pfo-mp-so[data-mp-id="'+mpId+'"]');

    const ruleType = typeEl ? typeEl.value : 'none';
    const ruleVal = valEl ? parseFloat(valEl.value) || 0 : 0;
    const offset = soEl ? parseInt(soEl.value) || 0 : 0;

    // Calculate price (mirrors PriceRuleType::apply)
    let calcSale = basePrice;
    let calcList = listPrice;
    if (ruleType === 'percent_increase') { calcSale = Math.ceil(basePrice * (1 + ruleVal / 100)); calcList = Math.ceil(listPrice * (1 + ruleVal / 100)); }
    else if (ruleType === 'percent_decrease') { calcSale = Math.ceil(basePrice * (1 - ruleVal / 100)); calcList = Math.ceil(listPrice * (1 - ruleVal / 100)); }
    else if (ruleType === 'fixed_increase') { calcSale = basePrice + ruleVal; calcList = listPrice + ruleVal; }
    else if (ruleType === 'fixed_decrease') { calcSale = basePrice - ruleVal; calcList = listPrice - ruleVal; }
    if (calcSale < 0) calcSale = 0;
    if (calcList < calcSale) calcList = calcSale;

    const computedStock = Math.max(0, stockQty - offset);

    const priceEl = document.getElementById('pfoMpPrice-'+mpId);
    const listEl = document.getElementById('pfoMpList-'+mpId);
    const stockEl = document.getElementById('pfoMpStock-'+mpId);
    if (priceEl) priceEl.textContent = formatMoney(calcSale);
    if (listEl) listEl.textContent = formatMoney(calcList);
    if (stockEl) stockEl.textContent = computedStock;
}

// Recalculate all MP previews when price/stock changes in Genel tab
function pfoRecalcAllPreviews() {
    if (!pfoMpData) return;
    document.querySelectorAll('.pfo-mp-card').forEach(card => {
        const mpId = card.dataset.mpId;
        if (mpId) pfoCalcPreview(parseInt(mpId));
    });
}

// Bind price/stock inputs to live-update sidebar previews
['pfoBasePrice','pfoListPrice','pfoStockQty'].forEach(id => {
    document.getElementById(id).addEventListener('input', pfoRecalcAllPreviews);
});

function formatMoney(v) {
    return parseFloat(v || 0).toLocaleString('tr-TR', { minimumFractionDigits: 2, maximumFractionDigits: 2 }) + ' ₺';
}

async function pfoSaveMpSettings() {
    if (!pfoEditId) { Toast.show('Önce ürünü kaydedin', 'warning'); return; }

    const cards = document.querySelectorAll('.pfo-mp-card');
    const marketplaces = [];

    cards.forEach(card => {
        const mpId = parseInt(card.dataset.mpId);
        const toggle = card.querySelector('.pfo-mp-toggle');
        const prt = card.querySelector('.pfo-mp-prt');
        const prv = card.querySelector('.pfo-mp-prv');
        const so = card.querySelector('.pfo-mp-so');

        marketplaces.push({
            marketplace_id: mpId,
            enabled: toggle ? toggle.checked : false,
            price_rule_type: prt ? prt.value : 'none',
            price_rule_value: prv ? parseFloat(prv.value) || 0 : 0,
            stock_offset: so ? parseInt(so.value) || 0 : 0,
        });
    });

    try {
        const res = await fetch('/products/'+pfoEditId+'/marketplace-settings', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest', 'X-CSRF-Token': CSRF },
            body: JSON.stringify({ _csrf_token: CSRF, marketplaces })
        });
        const json = await res.json();
        if (json.success) {
            Toast.show(json.message || 'Pazaryeri ayarları kaydedildi', 'success');
            // Reload to get updated calculated prices from server
            pfoLoadMarketplaces(pfoEditId);
        } else {
            Toast.show(json.message || 'Hata oluştu', 'error');
        }
    } catch(e) {
        Toast.show('Bağlantı hatası: ' + e.message, 'error');
    }
}

async function pfoPublishMp(mpId) {
    if (!pfoEditId) return;

    const btn = event.target.closest('button');
    const origHTML = btn.innerHTML;
    btn.innerHTML = '<i data-lucide="loader" style="width:12px;height:12px;animation:spin 1s linear infinite"></i> Yayınlanıyor...';
    btn.disabled = true;
    if (typeof lucide !== 'undefined') lucide.createIcons();

    try {
        // First save current settings
        await pfoSaveMpSettings();

        // Then publish
        const fd = new FormData();
        fd.append('_csrf_token', CSRF);
        fd.append('marketplace_id', mpId);
        fd.append('product_ids', pfoEditId);

        const res = await fetch('/products/publish', {
            method: 'POST',
            headers: { 'X-Requested-With': 'XMLHttpRequest' },
            body: fd
        });
        const json = await res.json();
        if (json.success) {
            const batchInfo = json.data && json.data.batch_ids && json.data.batch_ids.length ? ' Batch: ' + json.data.batch_ids.join(', ') : '';
            Toast.show((json.message || 'Ürün yayınlandı') + batchInfo, 'success');
            pfoLoadMarketplaces(pfoEditId);
        } else {
            Toast.show(json.message || 'Yayınlama hatası', 'error', 5000);
        }
    } catch(e) {
        Toast.show('Bağlantı hatası: ' + e.message, 'error');
    } finally {
        btn.innerHTML = origHTML;
        btn.disabled = false;
        if (typeof lucide !== 'undefined') lucide.createIcons();
    }
}

async function pfoSyncMp(mpId, syncType) {
    if (!pfoEditId) return;

    Toast.show('Senkronizasyon başlatılıyor...', 'info', 2000);

    try {
        const res = await fetch('/products/'+pfoEditId+'/sync', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest', 'X-CSRF-Token': CSRF },
            body: JSON.stringify({ _csrf_token: CSRF, marketplace_id: mpId, sync_type: syncType })
        });
        const json = await res.json();
        if (json.success) {
            Toast.show(json.message || 'Senkronizasyon tamamlandı', 'success');
            pfoLoadMarketplaces(pfoEditId);
        } else {
            Toast.show(json.message || 'Sync hatası', 'error');
        }
    } catch(e) {
        Toast.show('Bağlantı hatası: ' + e.message, 'error');
    }
}

// ═══ SEO PREVIEW ═══
function pfoUpdateSeoPreview() {
    const title = document.getElementById('pfoSeoTitle').value || document.getElementById('pfoName').value || 'Ürün başlığı';
    const desc = document.getElementById('pfoSeoDesc').value || 'Ürün açıklaması burada görünecek...';
    document.getElementById('pfoSeoPreviewTitle').textContent = title;
    document.getElementById('pfoSeoPreviewDesc').textContent = desc;
}

// ═══ SAVE ═══
async function prodFormSave() {
    const isEdit = !!pfoEditId;
    const name = document.getElementById('pfoName').value.trim();
    const sku = document.getElementById('pfoSku').value.trim();
    const basePrice = document.getElementById('pfoBasePrice').value;

    // Validation
    if (!name) { Toast.show('Ürün adı zorunludur', 'warning'); pfoSwitchTab('general'); document.getElementById('pfoName').focus(); return; }
    if (!isEdit && !sku) { Toast.show('SKU zorunludur', 'warning'); pfoSwitchTab('general'); document.getElementById('pfoSku').focus(); return; }
    if (!basePrice || parseFloat(basePrice) <= 0) { Toast.show('Satış fiyatı zorunludur', 'warning'); pfoSwitchTab('general'); document.getElementById('pfoBasePrice').focus(); return; }
    if (!document.getElementById('pfoCategory').value) { Toast.show('Kategori seçimi zorunludur', 'warning'); pfoSwitchTab('general'); document.getElementById('pfoCategory').focus(); return; }

    // Get Quill content
    let description = '';
    let shortDescription = '';
    if (pfoQuillDesc) description = pfoQuillDesc.root.innerHTML === '<p><br></p>' ? '' : pfoQuillDesc.root.innerHTML;
    else description = pfoDescContent;
    if (pfoQuillShortDesc) shortDescription = pfoQuillShortDesc.root.innerHTML === '<p><br></p>' ? '' : pfoQuillShortDesc.root.innerHTML;
    else shortDescription = pfoShortDescContent;

    // Build form data
    const fd = new FormData();
    fd.append('_csrf_token', CSRF);
    fd.append('name', name);
    if (!isEdit) fd.append('sku', sku);
    fd.append('barcode', document.getElementById('pfoBarcode').value.trim());
    fd.append('category_id', document.getElementById('pfoCategory').value);
    fd.append('brand_id', document.getElementById('pfoBrand').value);
    fd.append('description', description);
    fd.append('short_description', shortDescription);
    fd.append('base_price', basePrice);
    fd.append('list_price', document.getElementById('pfoListPrice').value || '0');
    fd.append('cost_price', document.getElementById('pfoCostPrice').value || '0');
    fd.append('tax_rate', document.getElementById('pfoTaxRate').value);
    fd.append('stock_qty', document.getElementById('pfoStockQty').value || '0');
    fd.append('min_stock_alert', document.getElementById('pfoMinStock').value || '5');
    fd.append('weight', document.getElementById('pfoWeight').value);
    fd.append('desi', document.getElementById('pfoDesi').value);
    fd.append('unit', document.getElementById('pfoUnit').value);
    fd.append('is_active', document.getElementById('pfoIsActive').checked ? '1' : '0');
    fd.append('images_json', JSON.stringify(pfoImages));
    fd.append('main_image', pfoImages[0]?.url || '');
    fd.append('seo_title', document.getElementById('pfoSeoTitle').value);
    fd.append('seo_description', document.getElementById('pfoSeoDesc').value);

    // Attributes
    document.querySelectorAll('[data-attr-id]').forEach(el => {
        fd.append('attrs['+el.dataset.attrId+']', el.value);
    });

    // Variants
    const vrRows = document.querySelectorAll('#pfoVariantsList .pfo-vr-row:not(.pfo-vr-header)');
    vrRows.forEach((row, i) => {
        const inputs = row.querySelectorAll('input');
        fd.append('variants['+i+'][name]', inputs[0]?.value || '');
        fd.append('variants['+i+'][sku]', inputs[1]?.value || '');
        fd.append('variants['+i+'][barcode]', inputs[2]?.value || '');
        fd.append('variants['+i+'][price]', inputs[3]?.value || '');
        fd.append('variants['+i+'][stock]', inputs[4]?.value || '0');
    });

    // Disable save buttons
    const saveBtn = document.getElementById('pfoSaveBtn');
    const origHTML = saveBtn.innerHTML;
    saveBtn.innerHTML = '<i data-lucide="loader" style="width:14px;height:14px;animation:spin 1s linear infinite"></i> Kaydediliyor...';
    saveBtn.disabled = true;
    if (typeof lucide !== 'undefined') lucide.createIcons();

    const url = isEdit ? '/products/'+pfoEditId : '/products';

    try {
        const res = await fetch(url, {
            method: 'POST',
            headers: { 'X-Requested-With': 'XMLHttpRequest' },
            body: fd
        });
        const json = await res.json();

        if (json.success) {
            table.ajax.reload(null, false);
            if (isEdit) {
                Toast.show(json.message || 'Ürün güncellendi', 'success');
                prodFormClose();
            } else {
                // Smart create-to-edit: don't close, switch to edit mode
                const newId = json.data?.id;
                if (newId) {
                    pfoEditId = newId;
                    Toast.show('Ürün oluşturuldu! Şimdi pazaryeri ayarlarını yapabilirsiniz.', 'success', 4000);
                    // Switch UI to edit mode
                    document.querySelector('#pfoTitle span').textContent = 'Ürün Düzenle';
                    document.querySelector('#pfoTitle [data-lucide]').setAttribute('data-lucide', 'edit-3');
                    document.getElementById('pfoSaveBtn').querySelector('span').textContent = 'Güncelle';
                    document.getElementById('pfoSaveBtnText2').textContent = 'Güncelle';
                    document.getElementById('pfoSku').readOnly = true;
                    document.getElementById('pfoSku').style.opacity = '0.6';
                    document.getElementById('pfoStockQty').readOnly = true;
                    document.getElementById('pfoStockQty').style.opacity = '0.6';
                    document.getElementById('pfoFooterInfo').textContent = 'Ürün #'+newId;
                    // Show sidebar and load marketplace data
                    document.getElementById('pfoSidebar').classList.add('active');
                    pfoLoadMarketplaces(newId);
                    if (typeof lucide !== 'undefined') lucide.createIcons();
                } else {
                    Toast.show(json.message || 'Ürün oluşturuldu', 'success');
                    prodFormClose();
                }
            }
        } else {
            Toast.show(json.message || 'Hata oluştu', 'error', 5000);
        }
    } catch(e) {
        Toast.show('Bağlantı hatası: ' + e.message, 'error');
    } finally {
        saveBtn.innerHTML = origHTML;
        saveBtn.disabled = false;
        if (typeof lucide !== 'undefined') lucide.createIcons();
    }
}

// ═══ Quill init on tab switch — load saved content ═══
const origSwitchTab = pfoSwitchTab;
pfoSwitchTab = function(tabId) {
    origSwitchTab(tabId);
    if (tabId === 'images' && pfoQuillDesc) {
        // Set content if we have saved content
        if (pfoDescContent && pfoQuillDesc.root.innerHTML === '<p><br></p>') {
            pfoQuillDesc.root.innerHTML = pfoDescContent;
        }
        if (pfoShortDescContent && pfoQuillShortDesc && pfoQuillShortDesc.root.innerHTML === '<p><br></p>') {
            pfoQuillShortDesc.root.innerHTML = pfoShortDescContent;
        }
    }
};

// ═══ Form overlay ESC close ═══
document.getElementById('prodFormOverlay').addEventListener('click', function(e) {
    if (e.target === this) prodFormClose();
});

// Init Lucide
if(typeof lucide !== 'undefined') lucide.createIcons();
</script>
