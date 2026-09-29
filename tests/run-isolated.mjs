// Run from the project root: node backend/tests/run-isolated.mjs
// Real MySQL + HTTP integration tests; temporary database and in-memory SMTP inbox.
import { spawn } from 'node:child_process'
import net from 'node:net'
import http from 'node:http'
import { randomBytes } from 'node:crypto'
import { fileURLToPath } from 'node:url'
import path from 'node:path'
import fs from 'node:fs'

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../..')
const uploadTmp = fs.mkdtempSync(path.join(root, 'backend', 'storage', 'test-upload-'))
const database = `nre_test_${randomBytes(6).toString('hex')}`
const messages = []
const smtp = net.createServer(socket => {
  socket.on('error', () => {})
  socket.write('220 localhost test SMTP\r\n')
  let buffer = '', data = false, message = '', recipient = ''
  socket.on('data', chunk => {
    buffer += chunk.toString()
    while (buffer.includes('\r\n')) {
      const end = buffer.indexOf('\r\n'), line = buffer.slice(0, end)
      buffer = buffer.slice(end + 2)
      if (data) {
        if (line === '.') {
          const body = Buffer.from(message.split('\r\n\r\n')[1] ?? '', 'base64').toString()
          messages.push({recipient, body}); message = ''; data = false
          socket.write('250 accepted\r\n')
        } else message += line + '\r\n'
      } else if (line.startsWith('EHLO')) socket.write('250 localhost\r\n')
      else if (line.startsWith('RCPT TO:')) {recipient = line.slice(9).replace(/[<>]/g, ''); socket.write('250 recipient\r\n')}
      else if (line === 'DATA') {data = true; socket.write('354 send data\r\n')}
      else if (line === 'QUIT') {socket.end('221 bye\r\n')}
      else socket.write('250 ok\r\n')
    }
  })
})
const inbox = http.createServer((req, res) => {
  res.setHeader('Content-Type', 'application/json')
  res.setHeader('Cache-Control', 'no-store')
  res.end(JSON.stringify(messages))
})
const listen = server => new Promise(resolve => server.listen(0, '127.0.0.1', resolve))
await listen(smtp); await listen(inbox)
const portReservation = net.createServer(); await listen(portReservation)
const port = portReservation.address().port
await new Promise(resolve => portReservation.close(resolve))
const env = {...process.env, DB_NAME: database, APP_ENV: 'development', SMTP_HOST: '127.0.0.1', SMTP_PORT: String(smtp.address().port), SMTP_ENCRYPTION: 'none', SMTP_USERNAME: '', SMTP_PASSWORD: '', MAIL_FROM_EMAIL: 'test@nre.local', FRONTEND_URL: 'http://localhost:5173', TEST_MAILBOX_URL: `http://127.0.0.1:${inbox.address().port}`, AUTH_MAX_FAILED_LOGINS: '30'}
for (const [role, name, username, email] of [['SUPER_ADMIN','Super Admin','superadmin','superadmin@crm.local'],['ADMIN','Demo Admin','admin','admin@crm.local'],['USER','Demo User','user','user@crm.local']]) {
  Object.assign(env, {[`SEED_${role}_NAME`]: name, [`SEED_${role}_USERNAME`]: username, [`SEED_${role}_EMAIL`]: email, [`SEED_${role}_PASSWORD`]: 'TestOnlyPassw0rd!'})
}
const php = args => new Promise((resolve, reject) => {
  const p = spawn('php', ['-d', 'variables_order=EGPCS', ...args], {cwd: root, env, stdio: 'inherit', windowsHide: true})
  p.on('error', reject); p.on('exit', code => resolve(code ?? 1))
})
let server, vite
let result = 1
try {
  console.log(`Testing isolated database ${database}`)
  if (await php(['backend/database/setup.php'])) throw Error('Test setup failed')
  server = spawn('php', ['-d','variables_order=EGPCS',`-d`,`upload_tmp_dir=${uploadTmp}`,'-S',`127.0.0.1:${port}`,'-t','backend'], {cwd:root, env, stdio:'ignore', windowsHide:true})
  server.on('error', () => {})
  for (let i = 0; i < 50; i++) {
    try {await fetch(`http://127.0.0.1:${port}/api/auth/me.php`); break}
    catch {await new Promise(resolve => setTimeout(resolve, 100))}
  }
  if (process.argv.includes('--ui')) {
    const base = `http://127.0.0.1:${port}/api`
    const login = await fetch(`${base}/auth/login.php`, {method:'POST', headers:{'Content-Type':'application/json'}, body:JSON.stringify({login:'user', password:'TestOnlyPassw0rd!'})})
    const cookie = login.headers.get('set-cookie').split(';')[0]
    const created = await fetch(`${base}/records/create.php`, {method:'POST', headers:{'Content-Type':'application/json', Cookie:cookie}, body:JSON.stringify({title:'Garden View Villa',property_type_id:2,transaction_type:'sale',property_category_id:1,property_category:'residential',process_stage_id:1,city:'Chennai',purpose:'sale',sale_amount:8500000,party:{name:'Demo Contact',phone:'9876543210',email:'demo@example.test'},submit:true})})
    if (!created.ok) throw Error('UI fixture creation failed')
    vite = spawn(process.execPath, ['node_modules/vite/bin/vite.js','--port','5174'], {cwd:path.join(root,'frontend'),env:{...env,BACKEND_URL:`http://127.0.0.1:${port}`},stdio:'ignore',windowsHide:true})
    console.log('UI test app: http://localhost:5174 — login user / TestOnlyPassw0rd! (temporary fixtures only). Press Enter here to remove the test database and stop servers.')
    await new Promise(resolve => {process.stdin.resume(); process.stdin.once('data',resolve)})
    result = 0
  } else {
    result = await php(['backend/tests/api_test.php', `http://127.0.0.1:${port}/api`])
  }
} finally {
  if (server) server.kill()
  if (vite) vite.kill()
  process.stdin.pause()
  smtp.close(); inbox.close()
  messages.length = 0
  await php(['-r', `require 'backend/bootstrap.php'; $name = Config::get('db.name'); if (!preg_match('/^nre_test_[a-f0-9]{12}$/', $name)) exit(1); Database::connection()->exec('DROP DATABASE ' . $name); echo "Temporary test database removed.\\n";`])
  fs.rmSync(uploadTmp, {recursive:true, force:true})
}
process.exitCode = result
