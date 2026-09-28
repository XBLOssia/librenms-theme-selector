import re, json
RULES = [
    (r'^navbar-default-navbar-nav-li-a-', 'navbar-link-'),
    (r'^navbar-nav-li-a-hover-fa-nav-icons-', 'navbar-icon-hover-'),
    (r'^navbar-default-navbar-brand-', 'navbar-brand-'),
    (r'^navbar-default-dropdown-menu-', 'navbar-dropdown-'),
    (r'^navbar-default-', 'navbar-'),
    (r'^dropdown-menu-li-a-hover-', 'dropdown-item-hover-'),
    (r'^dropdown-menu-li-a-', 'dropdown-item-'),
    (r'^dropdown-menu-', 'dropdown-'),
    (r'^dropdown-submenu-dropdown-menu-', 'dropdown-submenu-'),
    (r'^panel-default-panel-heading-', 'panel-heading-'),
    (r'^table-thead-tr-th-', 'table-head-'),
    (r'^table-tbody-tr-td-', 'table-cell-'),
    (r'^table-striped-tbody-tr-nth-of-type-(odd|even)-', r'table-stripe-\1-'),
    (r'^table-hover-tbody-tr-hover-', 'table-row-hover-'),
    (r'^table-thead-tr-(success|info|warning|danger)-td-', r'table-row-\1-'),
    (r'^webkit-scrollbar-', 'scrollbar-'),
    (r'^root-scrollbar-', 'scrollbar-'),
    (r'^grid-stack-item-content-header-', 'widget-bar-'),
    (r'^gs-w-', 'widget-'),
    (r'^pace-pace-progress-inner-', 'progress-glow-'),
    (r'^pace-pace-progress-', 'progress-bar-'),
    (r'^badge-navbar-user-badge-danger-', 'alert-badge-'),
    (r'^badge-navbar-user-', 'user-badge-'),
    (r'^bootstrap-switch-bootstrap-switch-focuse-', 'switch-focus-'),
    (r'^nav-tabs-li-active-a-', 'tab-active-'),
    (r'^nav-tabs-li-a-', 'tab-'),
    (r'^nav-tabs-', 'tabs-'),
    (r'^widget-alert-totals-label-', 'widget-alert-label-'),
    (r'^dashboard-widget-title-', 'widget-title-'),
    (r'^input-group-addon-', 'addon-'),
    (r'^graph-image-', 'graph-'),
    (r'^device-link-', 'device-'),
    (r'^pagemenu-selected-', 'pagemenu-active-'),
    (r'^leaflet-tile-', 'map-tile-'),
    (r'^form-control-focus-', 'input-focus-'),
    (r'^form-control-', 'input-'),
    (r'^modal-content-', 'modal-'),
    (r'^lnms-btn-', 'lnms-btn-'),
    (r'^a-hover-', 'link-hover-'),
    (r'^a-', 'link-'),
    (r'^tt-menu-', 'typeahead-'),
    (r'-shadow$', '-shadow'),
]
def rename(n):
    for pat, rep in RULES:
        n2 = re.sub(pat, rep, n)
        if n2 != n:
            n = n2
            break
    return n
if __name__ == '__main__':
    import sys
    exec(open('inventory.py').read().split("print(dict(cat)")[0])
    detail = [n[5:] for n in T['terran'] if n[5:] not in roles]
    m = {n: rename(n) for n in detail}
    assert len(set(m.values())) == len(m), [v for v in m.values() if list(m.values()).count(v) > 1]
    for k, v in m.items():
        print(f'{v:48} {"" if k == v else "<- " + k}')
