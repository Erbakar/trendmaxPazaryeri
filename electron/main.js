/**
 * OzyConnect Panel — Electron Ana Process (İskelet)
 * © 2025 OziyWeb — Tüm Hakları Saklıdır.
 *
 * Kullanıcı bu ekranda sunucu URL'sini girer.
 * Electron bir BrowserWindow açar ve o URL'e bağlanır.
 */

const { app, BrowserWindow, Menu, dialog } = require('electron');
const path = require('path');

let mainWindow;

function createWindow() {
    mainWindow = new BrowserWindow({
        width: 1280,
        height: 800,
        minWidth: 1024,
        minHeight: 600,
        title: 'OzyConnect — OziyWeb',
        icon: path.join(__dirname, 'assets', 'icon.png'),
        webPreferences: {
            preload: path.join(__dirname, 'preload.js'),
            nodeIntegration: false,
            contextIsolation: true,
        },
    });

    // URL giriş sayfasını yükle veya kayıtlı URL'ye bağlan
    const savedUrl = ''; // TODO: electron-store ile kaydet
    if (savedUrl) {
        mainWindow.loadURL(savedUrl);
    } else {
        mainWindow.loadFile('connect.html');
    }

    mainWindow.on('closed', () => { mainWindow = null; });
}

// Menü
const menuTemplate = [
    {
        label: 'Dosya',
        submenu: [
            { label: 'Sunucu Değiştir', click: () => mainWindow.loadFile('connect.html') },
            { type: 'separator' },
            { label: 'Çıkış', accelerator: 'CmdOrCtrl+Q', click: () => app.quit() },
        ],
    },
    {
        label: 'Görünüm',
        submenu: [
            { label: 'Tam Ekran', accelerator: 'F11', click: () => mainWindow.setFullScreen(!mainWindow.isFullScreen()) },
            { role: 'reload' },
            { role: 'toggleDevTools' },
        ],
    },
    {
        label: 'Yardım',
        submenu: [
            {
                label: 'Hakkında',
                click: () => dialog.showMessageBox({
                    type: 'info',
                    title: 'OzyConnect',
                    message: 'OzyConnect v1.0.0\n© 2025 OziyWeb\nwww.oziyweb.com.tr',
                }),
            },
        ],
    },
];

app.whenReady().then(() => {
    Menu.setApplicationMenu(Menu.buildFromTemplate(menuTemplate));
    createWindow();
});

app.on('window-all-closed', () => { if (process.platform !== 'darwin') app.quit(); });
app.on('activate', () => { if (mainWindow === null) createWindow(); });
