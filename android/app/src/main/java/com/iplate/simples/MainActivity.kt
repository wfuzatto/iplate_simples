package com.iplate.simples

import android.content.Context
import android.graphics.Bitmap
import android.graphics.BitmapFactory
import android.graphics.Matrix
import android.net.Uri
import android.os.Bundle
import android.os.Environment
import androidx.activity.ComponentActivity
import androidx.activity.compose.setContent
import androidx.activity.compose.rememberLauncherForActivityResult
import androidx.activity.result.contract.ActivityResultContracts
import androidx.compose.foundation.Image
import androidx.compose.foundation.background
import androidx.compose.foundation.clickable
import androidx.compose.foundation.gestures.detectTransformGestures
import androidx.compose.foundation.layout.*
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.foundation.text.KeyboardOptions
import androidx.compose.foundation.verticalScroll
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.filled.*
import androidx.compose.material3.*
import androidx.compose.runtime.*
import androidx.compose.runtime.snapshots.SnapshotStateMap
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.geometry.Offset
import androidx.compose.ui.graphics.asImageBitmap
import androidx.compose.ui.graphics.graphicsLayer
import androidx.compose.ui.input.pointer.pointerInput
import androidx.compose.ui.layout.ContentScale
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.input.KeyboardType
import androidx.compose.ui.text.input.PasswordVisualTransformation
import androidx.compose.ui.unit.dp
import androidx.core.content.FileProvider
import androidx.exifinterface.media.ExifInterface
import com.google.mlkit.vision.common.InputImage
import com.google.mlkit.vision.text.TextRecognition
import com.google.mlkit.vision.text.latin.TextRecognizerOptions
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.launch
import kotlinx.coroutines.suspendCancellableCoroutine
import kotlinx.coroutines.withContext
import okhttp3.FormBody
import okhttp3.MediaType.Companion.toMediaType
import okhttp3.MultipartBody
import okhttp3.OkHttpClient
import okhttp3.Request
import okhttp3.RequestBody.Companion.asRequestBody
import okhttp3.RequestBody.Companion.toRequestBody
import org.json.JSONObject
import java.io.File
import java.util.UUID
import java.util.concurrent.TimeUnit
import kotlin.coroutines.resume
import kotlin.coroutines.resumeWithException

private const val DEFAULT_BACKEND = ""
private const val SETTINGS = "iplate_simples_settings"

private enum class PhotoType(val key: String, val caption: String) {
    PLATE("plate_photo", "Placa"),
    FRONT("front_photo", "Frente"),
    RIGHT("right_photo", "Lateral direita"),
    LEFT("left_photo", "Lateral esquerda"),
    REAR("rear_photo", "Traseira"),
    ROOF("roof_photo", "Teto")
}

private data class Guest(
    val code: String,
    val name: String,
    val status: String,
    val arrival: String,
    val validated: Boolean
)

class MainActivity : ComponentActivity() {
    override fun onCreate(savedInstanceState: Bundle?) {
        super.onCreate(savedInstanceState)
        setContent { MaterialTheme(colorScheme = lightColorScheme()) { InspectionApp() } }
    }
}

@Composable
private fun InspectionApp() {
    val context = LocalContext.current
    val prefs = remember { context.getSharedPreferences(SETTINGS, Context.MODE_PRIVATE) }
    var server by remember { mutableStateOf(prefs.getString("server", DEFAULT_BACKEND) ?: DEFAULT_BACKEND) }
    var newServer by remember { mutableStateOf(server) }
    var settingsOpen by remember { mutableStateOf(false) }
    var username by remember { mutableStateOf(prefs.getString("username", "") ?: "") }
    var password by remember { mutableStateOf("") }
    var token by remember { mutableStateOf("") }
    var demoMode by remember { mutableStateOf(false) }
    var serverStatus by remember { mutableStateOf("") }
    var term by remember { mutableStateOf("") }
    var mode by remember { mutableStateOf("code") }
    var selected by remember { mutableStateOf<Guest?>(null) }
    val results = remember { mutableStateListOf<Guest>() }
    var plate by remember { mutableStateOf("") }
    val photos = remember { mutableStateMapOf<PhotoType, File>() }
    var busy by remember { mutableStateOf(false) }
    var notice by remember { mutableStateOf("") }
    var preview by remember { mutableStateOf<File?>(null) }
    var pendingType by remember { mutableStateOf<PhotoType?>(null) }
    var pendingFile by remember { mutableStateOf<File?>(null) }
    val scope = rememberCoroutineScope()

    val capture = rememberLauncherForActivityResult(ActivityResultContracts.TakePicture()) { success ->
        val type = pendingType
        val file = pendingFile
        pendingType = null
        pendingFile = null
        if (success && type != null && file != null && file.exists() && file.length() > 0) {
            photos[type] = file
            if (type == PhotoType.PLATE) {
                scope.launch {
                    try {
                        val recognized = recognizePlate(context, file)
                        if (recognized != null) { plate = recognized; notice = "Placa identificada pelo OCR. Confira antes de enviar." }
                        else notice = "OCR não identificou a placa. Digite-a manualmente."
                    } catch (ex: Exception) { notice = "OCR indisponível: ${ex.message}. Digite a placa." }
                }
            }
        } else {
            file?.delete()
            if (!success) notice = "Fotografia não realizada."
        }
    }

    fun runAction(block: suspend () -> Unit) {
        if (busy) return
        scope.launch {
            busy = true
            notice = ""
            try { block() } catch (ex: Exception) { notice = ex.message ?: "Ocorreu um erro." }
            finally { busy = false }
        }
    }

    Column(Modifier.fillMaxSize().background(MaterialTheme.colorScheme.background)) {
        Row(Modifier.fillMaxWidth().background(MaterialTheme.colorScheme.primaryContainer).padding(14.dp),
            verticalAlignment = Alignment.CenterVertically) {
            Icon(Icons.Default.DirectionsCar, contentDescription = null)
            Spacer(Modifier.width(10.dp))
            Text("iPlate Simples", style = MaterialTheme.typography.titleLarge, fontWeight = FontWeight.Bold,
                modifier = Modifier.weight(1f))
            IconButton(onClick = { newServer = server; settingsOpen = true }) {
                Icon(Icons.Default.Settings, contentDescription = "Configurações")
            }
        }
        Column(Modifier.fillMaxSize().verticalScroll(rememberScrollState()).padding(16.dp),
            verticalArrangement = Arrangement.spacedBy(12.dp)) {
            if (token.isBlank()) {
                Text("Acesso do operador", style = MaterialTheme.typography.titleMedium)
                OutlinedTextField(username, { username = it }, label = { Text("Usuário") },
                    modifier = Modifier.fillMaxWidth(), singleLine = true)
                OutlinedTextField(password, { password = it }, label = { Text("Senha") },
                    visualTransformation = PasswordVisualTransformation(), modifier = Modifier.fillMaxWidth(),
                    singleLine = true)
                Button(onClick = {
                    runAction {
                        Backend.verifyServer(server)
                        val current = Backend.login(server, username, password)
                        token = current
                        demoMode = false
                        password = ""
                        prefs.edit().putString("username", username).apply()
                    }
                }, enabled = !busy && username.isNotBlank() && password.isNotBlank(), modifier = Modifier.fillMaxWidth()) {
                    Text("Entrar")
                }
                OutlinedButton(onClick = {
                    demoMode = true
                    selected = Guest("RASCUNHO_LOCAL", "Teste de fotografias", "", "", false)
                    notice = "Modo teste local: não consulta hóspedes nem envia fotos ao servidor."
                }, modifier = Modifier.fillMaxWidth()) {
                    Text("Testar captura das 6 fotos (sem servidor)")
                }
            } else {
                if (demoMode) {
                    Text("Teste local de fotografias — nenhum dado será enviado.",
                        color = MaterialTheme.colorScheme.error)
                }
                Row(verticalAlignment = Alignment.CenterVertically) {
                    Text(if (demoMode) "Prévia local da câmera" else "Operador: $username", modifier = Modifier.weight(1f))
                    TextButton(onClick = { token = ""; demoMode = false; selected = null; results.clear() }) { Text(if (demoMode) "Voltar" else "Sair") }
                }
                if (!demoMode && selected == null) {
                    FilledTonalButton(onClick = {
                        selected = Guest("RASCUNHO_LOCAL", "Fotos ainda não vinculadas à reserva", "", "", false)
                    }, modifier = Modifier.fillMaxWidth()) { Text("Abrir cadastro das fotos antes da reserva") }
                }
                Text("Localizar hóspede", style = MaterialTheme.typography.titleMedium)
                Row(horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                    FilterChip(selected = mode == "code", onClick = { mode = "code"; results.clear(); selected = null },
                        label = { Text("Por reserva") })
                    FilterChip(selected = mode == "name", onClick = { mode = "name"; results.clear(); selected = null },
                        label = { Text("Por nome") })
                }
                OutlinedTextField(value = term, onValueChange = { term = it },
                    label = { Text(if (mode == "code") "Número completo da reserva" else "Nome do hóspede") },
                    modifier = Modifier.fillMaxWidth(), singleLine = true)
                Button(onClick = {
                    runAction {
                        val found = Backend.search(server, token, term, mode)
                        results.clear(); results.addAll(found)
                        selected = null
                        if (found.isEmpty()) notice = "Nenhum hóspede encontrado."
                    }
                }, enabled = !busy && !demoMode && term.trim().length >= 3, modifier = Modifier.fillMaxWidth()) {
                    Icon(Icons.Default.Search, contentDescription = null)
                    Spacer(Modifier.width(8.dp))
                    Text("Buscar")
                }
                results.forEach { guest ->
                    OutlinedCard(onClick = {
                        if (guest.validated) {
                            selected = guest
                            results.clear()
                        } else {
                            runAction {
                                val verified = Backend.search(server, token, guest.code, "code")
                                    .firstOrNull { it.code == guest.code }
                                    ?: throw IllegalStateException("Reserva não confirmada na API Expresso.")
                                selected = verified
                                results.clear()
                            }
                        }
                    }, modifier = Modifier.fillMaxWidth()) {
                        Column(Modifier.padding(12.dp)) {
                            Text(guest.name.ifBlank { "Hóspede" }, fontWeight = FontWeight.Bold)
                            Text("Reserva: ${guest.code} • ${guest.status}")
                            if (!guest.validated) Text("Toque para validar a reserva no Expresso",
                                style = MaterialTheme.typography.bodySmall)
                        }
                    }
                }
                selected?.let { guest ->
                    HorizontalDivider()
                    Text("Reserva ${guest.code}", style = MaterialTheme.typography.titleMedium)
                    Text("Hóspede: ${guest.name.ifBlank { "Não informado" }}")
                    if (guest.arrival.isNotBlank()) Text("Chegada: ${guest.arrival}")
                    if (!guest.validated) Text(
                        "Rascunho local: fotografe agora e depois localize uma reserva para enviar.",
                        color = MaterialTheme.colorScheme.error,
                        style = MaterialTheme.typography.bodySmall
                    )
                    OutlinedTextField(plate, { plate = it.uppercase().filter { c -> c.isLetterOrDigit() }.take(7) },
                        label = { Text("Placa (OCR ou digitação)") }, modifier = Modifier.fillMaxWidth(),
                        singleLine = true)
                    Text("Fotografias em resolução original", style = MaterialTheme.typography.titleMedium)
                    Text("Toque em uma foto para ampliar. Toque em Fotografar para substituir.",
                        style = MaterialTheme.typography.bodySmall)
                    PhotoType.entries.chunked(2).forEach { pair ->
                        Row(horizontalArrangement = Arrangement.spacedBy(10.dp), modifier = Modifier.fillMaxWidth()) {
                            pair.forEach { type ->
                                PhotoTile(type, photos[type], onPreview = { preview = photos[type] },
                                    onCapture = {
                                        try {
                                            val dir = context.getExternalFilesDir(Environment.DIRECTORY_PICTURES)
                                                ?: throw IllegalStateException("Armazenamento indisponível.")
                                            dir.mkdirs()
                                            val file = File(dir, "${type.key}_${UUID.randomUUID()}.jpg")
                                            val uri = FileProvider.getUriForFile(
                                                context, "${context.packageName}.fileprovider", file
                                            )
                                            pendingType = type; pendingFile = file
                                            capture.launch(uri)
                                        } catch (ex: Exception) { notice = "Erro ao abrir câmera: ${ex.message}" }
                                    }, modifier = Modifier.weight(1f))
                            }
                        }
                    }
                    val complete = PhotoType.entries.all { photos[it]?.length() ?: 0L > 0 }
                    Button(onClick = {
                        runAction {
                            val normalized = plate.uppercase().replace(Regex("[^A-Z0-9]"), "")
                            if (!Regex("^[A-Z]{3}[0-9][A-Z0-9][0-9]{2}$").matches(normalized))
                                throw IllegalArgumentException("Confira a placa: use AAA1234 ou AAA1A23.")
                            if (!guest.validated || demoMode) throw IllegalArgumentException("Primeiro localize e valide a reserva.")
                            Backend.upload(server, token, guest.code, normalized, photos)
                            notice = "Vistoria enviada com sucesso, com 6 fotografias originais."
                        }
                    }, enabled = !busy && !demoMode && guest.validated && complete && plate.isNotBlank(),
                        modifier = Modifier.fillMaxWidth()) { Text("Enviar vistoria") }
                    if (!complete) Text("Capture todas as seis fotografias antes de enviar.",
                        color = MaterialTheme.colorScheme.error, style = MaterialTheme.typography.bodySmall)
                    OutlinedButton(onClick = {
                        selected = null; results.clear(); photos.clear(); plate = ""; term = ""; notice = ""
                    }, modifier = Modifier.fillMaxWidth()) { Text("Nova vistoria") }
                }
            }
            if (busy) LinearProgressIndicator(modifier = Modifier.fillMaxWidth())
            if (notice.isNotBlank()) Text(notice, color = MaterialTheme.colorScheme.primary)
        }
    }

    if (settingsOpen) {
        AlertDialog(onDismissRequest = { settingsOpen = false },
            title = { Text("Configurações") },
            text = {
                Column(verticalArrangement = Arrangement.spacedBy(10.dp)) {
                    OutlinedTextField(newServer, { newServer = it; serverStatus = "" }, modifier = Modifier.fillMaxWidth(),
                        label = { Text("URL do backend HTTPS") }, singleLine = true)
                    Text("Use a pasta do NOVO backend iPlate Simples, não o antigo endpoint de cadastro. Ex.: https://servidor/iplate_simples/backend",
                        style = MaterialTheme.typography.bodySmall)
                    OutlinedButton(onClick = {
                        runAction {
                            Backend.verifyServer(newServer)
                            serverStatus = "API iPlate Simples identificada corretamente."
                        }
                    }, enabled = !busy, modifier = Modifier.fillMaxWidth()) {
                        Text("Testar endereço da API")
                    }
                    if (serverStatus.isNotBlank()) Text(serverStatus, color = MaterialTheme.colorScheme.primary)
                    Text("Credenciais da API Expresso devem ficar apenas no servidor.",
                        style = MaterialTheme.typography.bodySmall)
                }
            }, confirmButton = {
                TextButton(onClick = {
                    val url = Backend.normalizeBase(newServer)
                    if (url.startsWith("https://") && Uri.parse(url).host != null && !Uri.parse(url).path.orEmpty().endsWith(".php")) {
                        if (url != server) { server = url; token = ""; selected = null; results.clear() }
                        prefs.edit().putString("server", url).apply()
                        settingsOpen = false
                    } else notice = "O servidor deve usar URL HTTPS válida."
                }) { Text("Salvar") }
            }, dismissButton = { TextButton(onClick = { settingsOpen = false }) { Text("Cancelar") } })
    }
    preview?.let { file ->
        PhotoZoom(file, onClose = { preview = null })
    }
}

@Composable
private fun PhotoTile(
    type: PhotoType, file: File?, onPreview: () -> Unit, onCapture: () -> Unit, modifier: Modifier = Modifier
) {
    ElevatedCard(modifier) {
        Column(Modifier.padding(10.dp), horizontalAlignment = Alignment.CenterHorizontally) {
            Text(type.caption, fontWeight = FontWeight.Medium, style = MaterialTheme.typography.bodyMedium)
            Spacer(Modifier.height(6.dp))
            if (file != null) {
                val bitmap by rememberPhoto(file, 450)
                if (bitmap != null) Image(bitmap!!.asImageBitmap(), contentDescription = "Ampliar ${type.caption}",
                    modifier = Modifier.fillMaxWidth().height(115.dp).clickable(onClick = onPreview),
                    contentScale = ContentScale.Crop)
                else Box(Modifier.fillMaxWidth().height(115.dp), contentAlignment = Alignment.Center) {
                    CircularProgressIndicator()
                }
                Text("${file.length() / 1024} KB • ampliar", style = MaterialTheme.typography.labelSmall,
                    modifier = Modifier.clickable(onClick = onPreview))
            } else Box(Modifier.fillMaxWidth().height(115.dp), contentAlignment = Alignment.Center) {
                Icon(Icons.Default.CameraAlt, contentDescription = null, modifier = Modifier.size(36.dp))
            }
            Spacer(Modifier.height(6.dp))
            FilledTonalButton(onClick = onCapture, modifier = Modifier.fillMaxWidth(),
                contentPadding = PaddingValues(horizontal = 4.dp, vertical = 0.dp)) {
                Text(if (file == null) "Fotografar" else "Refazer")
            }
        }
    }
}

@Composable
private fun rememberPhoto(file: File, maxDimension: Int): State<Bitmap?> =
    produceState<Bitmap?>(initialValue = null, key1 = file.absolutePath, key2 = maxDimension) {
        value = withContext(Dispatchers.IO) { decodePreview(file, maxDimension) }
    }

@Composable
private fun PhotoZoom(file: File, onClose: () -> Unit) {
    val bitmap by rememberPhoto(file, 3000)
    var scale by remember(file) { mutableFloatStateOf(1f) }
    var offset by remember(file) { mutableStateOf(Offset.Zero) }
    DialogWrapper(onClose) {
        Column(Modifier.fillMaxSize().background(MaterialTheme.colorScheme.surface)) {
            Row(Modifier.fillMaxWidth().padding(12.dp), verticalAlignment = Alignment.CenterVertically) {
                Text("Ampliar fotografia", modifier = Modifier.weight(1f))
                TextButton(onClick = onClose) { Text("Fechar") }
            }
            Box(Modifier.fillMaxSize().background(androidx.compose.ui.graphics.Color.Black),
                contentAlignment = Alignment.Center) {
                if (bitmap != null) {
                    Image(bitmap!!.asImageBitmap(), contentDescription = "Fotografia ampliada",
                        modifier = Modifier.fillMaxSize().pointerInput(file) {
                            detectTransformGestures { _, pan, zoom, _ ->
                                scale = (scale * zoom).coerceIn(1f, 8f)
                                offset = if (scale > 1f) offset + pan else Offset.Zero
                            }
                        }.graphicsLayer(
                            scaleX = scale, scaleY = scale,
                            translationX = offset.x, translationY = offset.y
                        ), contentScale = ContentScale.Fit)
                } else CircularProgressIndicator()
            }
        }
    }
}

@Composable
private fun DialogWrapper(onDismiss: () -> Unit, content: @Composable () -> Unit) {
    androidx.compose.ui.window.Dialog(onDismissRequest = onDismiss,
        properties = androidx.compose.ui.window.DialogProperties(usePlatformDefaultWidth = false),
        content = content)
}

private fun decodePreview(file: File, maxDimension: Int): Bitmap? {
    val opts = BitmapFactory.Options().apply { inJustDecodeBounds = true }
    BitmapFactory.decodeFile(file.absolutePath, opts)
    if (opts.outWidth <= 0 || opts.outHeight <= 0) return null
    var factor = 1
    while (maxOf(opts.outWidth, opts.outHeight) / (factor * 2) >= maxDimension) factor *= 2
    val bmp = BitmapFactory.decodeFile(file.absolutePath,
        BitmapFactory.Options().apply { inSampleSize = factor }) ?: return null
    val orientation = ExifInterface(file.absolutePath).getAttributeInt(
        ExifInterface.TAG_ORIENTATION, ExifInterface.ORIENTATION_NORMAL)
    val degrees = when (orientation) {
        ExifInterface.ORIENTATION_ROTATE_90 -> 90f
        ExifInterface.ORIENTATION_ROTATE_180 -> 180f
        ExifInterface.ORIENTATION_ROTATE_270 -> 270f
        else -> 0f
    }
    if (degrees == 0f) return bmp
    return Bitmap.createBitmap(bmp, 0, 0, bmp.width, bmp.height,
        Matrix().apply { postRotate(degrees) }, true).also { if (it !== bmp) bmp.recycle() }
}

private suspend fun recognizePlate(context: Context, file: File): String? {
    val recognizer = TextRecognition.getClient(TextRecognizerOptions.DEFAULT_OPTIONS)
    return try {
        val image = InputImage.fromFilePath(context, Uri.fromFile(file))
        val output = suspendCancellableCoroutine<String> { continuation ->
            recognizer.process(image).addOnSuccessListener { continuation.resume(it.text) }
                .addOnFailureListener { continuation.resumeWithException(it) }
        }
        val normalized = output.uppercase().replace(Regex("[^A-Z0-9]"), "")
        Regex("[A-Z]{3}[0-9][A-Z0-9][0-9]{2}").find(normalized)?.value
    } finally { recognizer.close() }
}

private object Backend {
    private val client = OkHttpClient.Builder()
        .connectTimeout(15, TimeUnit.SECONDS).readTimeout(80, TimeUnit.SECONDS)
        .writeTimeout(180, TimeUnit.SECONDS).build()

    fun normalizeBase(base: String): String {
        val trimmed = base.trim().trimEnd('/')
        // Aceita URL do login.php ou do endpoint antigo e extrai a raiz do backend.
        return trimmed.replace(Regex("/api(?:/[^/?#]+\\.php)?$", RegexOption.IGNORE_CASE), "")
    }

    private fun url(base: String, path: String): String {
        val root = normalizeBase(base)
        require(root.startsWith("https://")) { "Configure um servidor HTTPS nas opções." }
        return "$root/api/$path"
    }

    private fun request(request: Request): JSONObject {
        client.newCall(request).execute().use { response ->
            val body = response.body?.string() ?: ""
            val json = try { JSONObject(body) } catch (e: Exception) {
                throw IllegalStateException("Resposta não é JSON (HTTP ${response.code}).")
            }
            if (!response.isSuccessful || !json.optBoolean("success", false))
                throw IllegalStateException(json.optString("message", "Erro HTTP ${response.code}"))
            return json
        }
    }

    suspend fun verifyServer(base: String) = withContext(Dispatchers.IO) {
        val request = Request.Builder().url(url(base, "health.php")).get().build()
        try {
            client.newCall(request).execute().use { response ->
                val raw = response.body?.string() ?: ""
                val data = try { JSONObject(raw) } catch (_: Exception) { JSONObject() }
                if (!response.isSuccessful || data.optString("app") != "iplate_simples" ||
                    data.optInt("contract_version") != 1) {
                    throw IllegalStateException(
                        "Este endereço não aponta para a API do iPlate Simples. " +
                        "Instale o backend/ do repositório em uma pasta HTTPS do servidor e " +
                        "configure sua raiz aqui (sem /api/ e sem nome de arquivo). " +
                        "A API antiga de veículos não é compatível. HTTP ${response.code}."
                    )
                }
            }
        } catch (e: java.io.IOException) {
            throw IllegalStateException("Não foi possível acessar o backend iPlate Simples. Confira a URL, rede e certificado HTTPS.")
        }
    }

    suspend fun login(base: String, username: String, password: String): String =
        withContext(Dispatchers.IO) {
            val body = FormBody.Builder().add("username", username).add("password", password).build()
            val request = Request.Builder().url(url(base, "login.php")).post(body).build()
            val token = request(request).optJSONObject("user")?.optString("api_token").orEmpty()
            if (token.isBlank()) throw IllegalStateException("Token de operador ausente.")
            token
        }

    suspend fun search(base: String, token: String, term: String, mode: String): List<Guest> =
        withContext(Dispatchers.IO) {
            val jsonBody = JSONObject().put("api_token", token).put("term", term.trim())
                .put("mode", mode).toString()
            val body = jsonBody.toRequestBody("application/json; charset=utf-8".toMediaType())
            val request = Request.Builder().url(url(base, "reservation-search.php")).post(body).build()
            val array = request(request).optJSONArray("items") ?: return@withContext emptyList()
            buildList {
                for (i in 0 until array.length()) {
                    val obj = array.optJSONObject(i) ?: continue
                    val code = obj.optString("reservation_code")
                    if (code.isBlank()) continue
                    add(Guest(code, obj.optString("guest_name"), obj.optString("status"),
                        obj.optString("checkin_date"), obj.optString("source") == "expresso_api"))
                }
            }
        }

    suspend fun upload(base: String, token: String, reservation: String, plate: String,
                       photos: SnapshotStateMap<PhotoType, File>) = withContext(Dispatchers.IO) {
        val form = MultipartBody.Builder().setType(MultipartBody.FORM)
            .addFormDataPart("api_token", token)
            .addFormDataPart("reservation", reservation)
            .addFormDataPart("plate", plate)
        for (type in PhotoType.entries) {
            val file = photos[type] ?: throw IllegalArgumentException("Falta foto: ${type.caption}")
            if (!file.exists() || file.length() == 0L)
                throw IllegalArgumentException("Foto vazia: ${type.caption}")
            form.addFormDataPart(type.key, file.name, file.asRequestBody("image/jpeg".toMediaType()))
        }
        val request = Request.Builder().url(url(base, "entry-create.php")).post(form.build()).build()
        request(request)
    }
}
