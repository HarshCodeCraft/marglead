// ==============================================================================
// ORIGINAL SERVER WHATSAPP ENGINE SCRIPT (BACKUP)
// Backup Timestamp: 2026-09-19 23:31 IST
// Server: Ubuntu Oracle VPS (140.238.167.58)
// File Location: /var/www/html/whatsapp_engine/index.js
// ==============================================================================

const express = require('express');
const QRCode = require('qrcode');
const cors = require('cors');
const fs = require('fs');
const path = require('path');
const { makeWASocket, useMultiFileAuthState, DisconnectReason, Browsers } = require('@whiskeysockets/baileys');
const app = express();
app.use(express.json());
app.use(cors());
const PORT = process.env.PORT || 3000;
const sessions = new Map();
function getAuthDir(userId) {
    const dir = path.join(__dirname, 'auth_info_baileys', 'user_' + userId);
    if (!fs.existsSync(dir)) fs.mkdirSync(dir, { recursive: true });
    return dir;
}
async function startUserSession(userId) {
    userId = String(userId);
    if (sessions.has(userId)) { const s = sessions.get(userId); if (s.isStarting || s.connectionStatus === 'connected') return; }
    const sessionObj = sessions.get(userId) || { userId, qrCodeData: '', connectionStatus: 'disconnected', pairedPhone: '', sock: null, isStarting: false };
    sessions.set(userId, sessionObj);
    if (sessionObj.isStarting) return;
    sessionObj.isStarting = true;
    try {
        const authPath = getAuthDir(userId);
        const { state, saveCreds } = await useMultiFileAuthState(authPath);
        const sock = makeWASocket({ auth: state, browser: Browsers.ubuntu('Chrome'), printQRInTerminal: false });
        sessionObj.sock = sock;
        sock.ev.on('creds.update', saveCreds);
        sock.ev.on('connection.update', async (update) => {
            const { connection, lastDisconnect, qr } = update;
            if (qr) { sessionObj.qrCodeData = qr; sessionObj.connectionStatus = 'scan_qr'; console.log('[User ' + userId + '] QR Ready'); }
            if (connection === 'close') {
                const code = lastDisconnect && lastDisconnect.error && lastDisconnect.error.output ? lastDisconnect.error.output.statusCode : 0;    
                const loggedOut = (code === DisconnectReason.loggedOut || code === 401);
                sessionObj.connectionStatus = 'disconnected'; sessionObj.pairedPhone = ''; sessionObj.isStarting = false;
                if (loggedOut) { try { fs.rmSync(authPath, { recursive: true, force: true }); } catch(e){} sessionObj.qrCodeData = ''; setTimeout(() => startUserSession(userId), 3000); }
                else { setTimeout(() => startUserSession(userId), 4000); }
                try { fetch('https://friendlyaisolution.com/api/whatsapp_web_engine.php?action=check_status&user_id=' + userId).catch(function(){}); } catch(e){}
            } else if (connection === 'open') {
                sessionObj.connectionStatus = 'connected'; sessionObj.qrCodeData = ''; sessionObj.isStarting = false;
                if (sock.user && sock.user.id) { sessionObj.pairedPhone = sock.user.id.split(':')[0].split('@')[0]; console.log('[User ' + userId + '] CONNECTED: ' + sessionObj.pairedPhone); }
                try { fetch('https://friendlyaisolution.com/api/whatsapp_web_engine.php?action=check_status&user_id=' + userId).catch(function(){}); } catch(e){}
            }
        });
    } catch(err) { console.error('[User ' + userId + '] Error:', err.message); sessionObj.isStarting = false; }
}
async function restoreAllSessions() {
    const base = path.join(__dirname, 'auth_info_baileys');
    if (!fs.existsSync(base)) return;
    for (const sub of fs.readdirSync(base)) {
        if (sub.startsWith('user_')) {
            const uid = sub.replace('user_', '');
            if (uid && fs.existsSync(path.join(base, sub, 'creds.json'))) { console.log('[Startup] Restoring user: ' + uid); await startUserSession(uid); }
        }
    }
}
restoreAllSessions();
app.get('/status', async function(req, res) {
    const userId = String((req.query && req.query.user_id) || (req.body && req.body.user_id) || '');
    if (!userId) return res.status(400).json({ status: 'error', message: 'user_id required' });
    const s = sessions.get(userId);
    if (!s) return res.json({ status: 'disconnected', phone_number: null, phone: null, user_id: userId, uptime: process.uptime() });
    res.json({ status: s.connectionStatus, phone_number: s.connectionStatus === 'connected' ? s.pairedPhone : null, phone: s.connectionStatus === 'connected' ? s.pairedPhone : null, user_id: userId, uptime: process.uptime() });
});
app.get('/qr', async function(req, res) {
    const userId = String((req.query && req.query.user_id) || (req.body && req.body.user_id) || '');
    if (!userId) return res.status(400).json({ status: 'error', message: 'user_id required' });
    await startUserSession(userId);
    const s = sessions.get(userId);
    if (!s) return res.json({ status: 'disconnected', user_id: userId });
    if (s.connectionStatus === 'connected') return res.json({ status: 'connected', phone: s.pairedPhone, phone_number: s.pairedPhone, user_id: userId });
    if (s.qrCodeData) {
        try { const img = await QRCode.toDataURL(s.qrCodeData); return res.json({ status: 'scan_qr', qr: s.qrCodeData, qr_image: img, user_id: userId }); }
        catch(e) { return res.json({ status: 'scan_qr', qr: s.qrCodeData, user_id: userId }); }
    }
    return res.json({ status: 'initializing', message: 'Generating QR... retry in 3s', user_id: userId });
});
app.post('/pairing-code', async function(req, res) {
    const userId = String((req.body && req.body.user_id) || (req.query && req.query.user_id) || '');
    const phone = req.body && req.body.phone;
    if (!userId || !phone) return res.status(400).json({ status: 'error', message: 'user_id and phone required.' });
    await startUserSession(userId);
    const s = sessions.get(userId);
    try {
        if (s && s.connectionStatus === 'connected') return res.json({ status: 'connected', phone: s.pairedPhone });
        if (s && s.sock && !s.sock.authState.creds.registered) { const code = await s.sock.requestPairingCode(phone.replace(/\D/g,'')); return res.json({ status: 'success', pairing_code: code }); }
        return res.status(400).json({ status: 'error', message: 'Engine initializing, retry in 3s.' });
    } catch(e) { return res.status(500).json({ status: 'error', message: e.message }); }
});
app.post('/send-message', async function(req, res) {
    const userId = String((req.body && req.body.user_id) || (req.query && req.query.user_id) || '');
    const recipient = req.body && req.body.recipient;
    const message = req.body && req.body.message;
    const pdf_url = req.body && req.body.pdf_url;
    if (!userId) return res.status(400).json({ status: 'error', message: 'user_id required' });
    if (!recipient || !message) return res.status(400).json({ status: 'error', message: 'recipient and message required.' });
    const s = sessions.get(userId);
    if (!s || s.connectionStatus !== 'connected') return res.status(503).json({ status: 'error', success: false, message: 'WhatsApp for user ' + userId + ' is ' + (s ? s.connectionStatus : 'not initialized') + '. Please scan QR.' });
    const jid = recipient.replace(/\D/g,'') + '@s.whatsapp.net';
    try {
        let result;
        if (pdf_url) { result = await s.sock.sendMessage(jid, { document: { url: pdf_url }, mimetype: 'application/pdf', fileName: 'Invoice.pdf', caption: message }); }
        else { result = await s.sock.sendMessage(jid, { text: message }); }
        return res.json({ status: 'success', success: true, message_id: result && result.key ? result.key.id : null, from_phone: s.pairedPhone, user_id: userId });
    } catch(err) { return res.status(500).json({ status: 'error', success: false, message: err.message }); }
});
app.post('/logout', async function(req, res) {
    const userId = String((req.body && req.body.user_id) || (req.query && req.query.user_id) || '');
    if (!userId) return res.status(400).json({ status: 'error', message: 'user_id required' });
    const s = sessions.get(userId);
    try {
        if (s && s.sock) await s.sock.logout();
        sessions.delete(userId);
        try { fs.rmSync(path.join(__dirname, 'auth_info_baileys', 'user_' + userId), { recursive: true, force: true }); } catch(e){}
        res.json({ status: 'success', message: 'User ' + userId + ' logged out.' });
    } catch(err) { res.json({ status: 'error', message: err.message }); }
});
app.get('/sessions', function(req, res) {
    const list = [];
    sessions.forEach(function(s, uid) { list.push({ user_id: uid, status: s.connectionStatus, phone: s.connectionStatus === 'connected' ? s.pairedPhone : null }); });
    res.json({ total: list.length, sessions: list });
});
app.listen(PORT, function() { console.log('WhatsApp Engine v3.0 (Strict Isolation) on port ' + PORT); });
