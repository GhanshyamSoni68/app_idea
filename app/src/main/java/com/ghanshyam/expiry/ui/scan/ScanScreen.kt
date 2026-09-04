package com.ghanshyam.expiry.ui.scan

import android.Manifest
import android.content.pm.PackageManager
import androidx.activity.compose.rememberLauncherForActivityResult
import androidx.activity.result.contract.ActivityResultContracts
import androidx.camera.core.CameraSelector
import androidx.camera.core.ImageAnalysis
import androidx.camera.core.Preview
import androidx.camera.lifecycle.ProcessCameraProvider
import androidx.camera.view.PreviewView
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.automirrored.filled.ArrowBack
import androidx.compose.material3.Button
import androidx.compose.material3.ExperimentalMaterial3Api
import androidx.compose.material3.Icon
import androidx.compose.material3.IconButton
import androidx.compose.material3.LinearProgressIndicator
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.Scaffold
import androidx.compose.material3.Surface
import androidx.compose.material3.Text
import androidx.compose.material3.TextButton
import androidx.compose.material3.TopAppBar
import androidx.compose.runtime.Composable
import androidx.compose.runtime.DisposableEffect
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.unit.dp
import androidx.compose.ui.viewinterop.AndroidView
import androidx.core.content.ContextCompat
import androidx.hilt.navigation.compose.hiltViewModel
import androidx.lifecycle.compose.LocalLifecycleOwner
import androidx.lifecycle.compose.collectAsStateWithLifecycle
import com.ghanshyam.expiry.R
import com.ghanshyam.expiry.ocr.DateCandidate
import com.ghanshyam.expiry.ui.currentLocale
import com.ghanshyam.expiry.ui.formatDate
import java.time.LocalDate
import java.util.concurrent.Executors

@OptIn(ExperimentalMaterial3Api::class)
@Composable
fun ScanScreen(
    onDateChosen: (LocalDate) -> Unit,
    onCancel: () -> Unit,
    viewModel: ScanViewModel = hiltViewModel(),
) {
    val context = LocalContext.current
    val state by viewModel.uiState.collectAsStateWithLifecycle()
    val locale = currentLocale()

    var hasCameraPermission by remember {
        mutableStateOf(
            ContextCompat.checkSelfPermission(context, Manifest.permission.CAMERA) ==
                PackageManager.PERMISSION_GRANTED,
        )
    }

    val permissionLauncher = rememberLauncherForActivityResult(
        contract = ActivityResultContracts.RequestPermission(),
        onResult = { granted -> hasCameraPermission = granted },
    )

    LaunchedEffect(Unit) {
        if (!hasCameraPermission) permissionLauncher.launch(Manifest.permission.CAMERA)
    }

    Scaffold(
        topBar = {
            TopAppBar(
                title = { Text(stringResource(R.string.title_scan)) },
                navigationIcon = {
                    IconButton(onClick = onCancel) {
                        Icon(
                            Icons.AutoMirrored.Filled.ArrowBack,
                            stringResource(R.string.cd_back),
                        )
                    }
                },
            )
        },
    ) { padding ->
        Box(modifier = Modifier.padding(padding).fillMaxSize()) {
            if (hasCameraPermission) {
                CameraPreview(
                    onFrame = { proxy -> viewModel.onFrame(proxy, locale) },
                    modifier = Modifier.fillMaxSize(),
                )
                ScanOverlay(
                    candidates = state.candidates,
                    showNoResults = state.showNoResults,
                    onDateChosen = onDateChosen,
                    onEnterManually = onCancel,
                    modifier = Modifier.align(Alignment.BottomCenter),
                )
            } else {
                PermissionRationale(
                    onGrant = { permissionLauncher.launch(Manifest.permission.CAMERA) },
                    onEnterManually = onCancel,
                )
            }
        }
    }
}

@Composable
private fun CameraPreview(
    onFrame: (androidx.camera.core.ImageProxy) -> Unit,
    modifier: Modifier = Modifier,
) {
    val context = LocalContext.current
    val lifecycleOwner = LocalLifecycleOwner.current

    // A dedicated single thread: analysis must not run on the main thread, and
    // a single thread keeps frames strictly ordered.
    val analysisExecutor = remember { Executors.newSingleThreadExecutor() }
    val previewView = remember {
        PreviewView(context).apply {
            scaleType = PreviewView.ScaleType.FILL_CENTER
        }
    }

    DisposableEffect(lifecycleOwner) {
        val providerFuture = ProcessCameraProvider.getInstance(context)
        var provider: ProcessCameraProvider? = null

        providerFuture.addListener(
            {
                provider = providerFuture.get()

                val preview = Preview.Builder().build()
                preview.setSurfaceProvider(previewView.surfaceProvider)

                val analysis = ImageAnalysis.Builder()
                    // Drop stale frames rather than buffering them: the user is
                    // moving the phone, so the newest frame is the only useful one.
                    .setBackpressureStrategy(ImageAnalysis.STRATEGY_KEEP_ONLY_LATEST)
                    .build()
                    .apply {
                        setAnalyzer(analysisExecutor) { proxy -> onFrame(proxy) }
                    }

                runCatching {
                    provider?.unbindAll()
                    provider?.bindToLifecycle(
                        lifecycleOwner,
                        CameraSelector.DEFAULT_BACK_CAMERA,
                        preview,
                        analysis,
                    )
                }
            },
            ContextCompat.getMainExecutor(context),
        )

        onDispose {
            provider?.unbindAll()
            analysisExecutor.shutdown()
        }
    }

    AndroidView(factory = { previewView }, modifier = modifier)
}

@Composable
private fun ScanOverlay(
    candidates: List<DateCandidate>,
    showNoResults: Boolean,
    onDateChosen: (LocalDate) -> Unit,
    onEnterManually: () -> Unit,
    modifier: Modifier = Modifier,
) {
    Surface(
        modifier = modifier.fillMaxWidth(),
        shape = RoundedCornerShape(topStart = 20.dp, topEnd = 20.dp),
        tonalElevation = 3.dp,
    ) {
        Column(
            modifier = Modifier.padding(16.dp),
            verticalArrangement = Arrangement.spacedBy(8.dp),
        ) {
            when {
                candidates.isNotEmpty() -> {
                    Text(
                        text = stringResource(R.string.scan_pick_date),
                        style = MaterialTheme.typography.titleMedium,
                    )
                    for (candidate in candidates) {
                        CandidateRow(candidate = candidate, onClick = { onDateChosen(candidate.date) })
                    }
                }

                showNoResults -> {
                    Text(
                        text = stringResource(R.string.scan_no_dates),
                        style = MaterialTheme.typography.bodyMedium,
                    )
                    TextButton(onClick = onEnterManually) {
                        Text(stringResource(R.string.action_enter_manually))
                    }
                }

                else -> {
                    Text(
                        text = stringResource(R.string.scan_instruction),
                        style = MaterialTheme.typography.bodyMedium,
                    )
                    LinearProgressIndicator(modifier = Modifier.fillMaxWidth())
                }
            }
        }
    }
}

@Composable
private fun CandidateRow(candidate: DateCandidate, onClick: () -> Unit) {
    Button(onClick = onClick, modifier = Modifier.fillMaxWidth()) {
        Column(modifier = Modifier.weight(1f)) {
            Text(formatDate(candidate.date))
            Text(
                // What the OCR actually read, so the user can tell two
                // similar-looking dates apart at a glance.
                text = candidate.matchedText,
                style = MaterialTheme.typography.bodySmall,
            )
        }
        Text(
            text = "${(candidate.confidence * 100).toInt()}%",
            style = MaterialTheme.typography.labelMedium,
        )
    }
}

@Composable
private fun PermissionRationale(onGrant: () -> Unit, onEnterManually: () -> Unit) {
    Column(
        modifier = Modifier
            .fillMaxSize()
            .padding(32.dp),
        verticalArrangement = Arrangement.Center,
        horizontalAlignment = Alignment.CenterHorizontally,
    ) {
        Text(
            text = stringResource(R.string.scan_permission_rationale),
            style = MaterialTheme.typography.bodyLarge,
        )
        Button(onClick = onGrant, modifier = Modifier.padding(top = 24.dp)) {
            Text(stringResource(R.string.action_grant_camera))
        }
        TextButton(onClick = onEnterManually) {
            Text(stringResource(R.string.action_enter_manually))
        }
    }
}
