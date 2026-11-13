/**
 * Otimização de imagens no navegador antes do upload do painel.
 *
 * O servidor normaliza tudo de forma autoritativa, mas enviar 8–15 MB de uma
 * foto de celular para depois ser reduzida desperdiça a conexão do operador e
 * pode esbarrar em `upload_max_filesize`. Para arquivos acima de 4 MB, o
 * navegador decodifica a imagem, reduz até o perfil do campo
 * (`data-image-max-width` / `data-image-max-height`), recomprime em WebP (ou
 * PNG no favicon) e substitui o arquivo no próprio input.
 *
 * Regras de segurança:
 * - só JPEG, PNG e WebP são tratados; HEIC e formatos que o navegador não
 *   decodifica seguem intactos para o servidor decidir;
 * - a imagem só é trocada quando o resultado fica menor que o original;
 * - qualquer falha mantém o arquivo escolhido, sem bloquear o envio.
 */
const OPTIMIZE_THRESHOLD_BYTES = 4 * 1024 * 1024;
const SUPPORTED_TYPES = new Set(['image/jpeg', 'image/png', 'image/webp']);

const formatBytes = (bytes) => {
    if (bytes < 1024) {
        return `${bytes} B`;
    }

    if (bytes < 1024 * 1024) {
        return `${(bytes / 1024).toFixed(0)} KB`;
    }

    return `${(bytes / (1024 * 1024)).toLocaleString('pt-BR', { maximumFractionDigits: 1 })} MB`;
};

const readConfig = (input) => {
    const data = input.dataset;

    return {
        maxBytes: Number(data.imageMaxBytes) || 0,
        maxWidth: Number(data.imageMaxWidth) || 0,
        maxHeight: Number(data.imageMaxHeight) || 0,
        maxPixels: Number(data.imageMaxPixels) || 0,
        quality: Number(data.imageQuality) || 82,
        format: data.imageFormat === 'png' ? 'image/png' : 'image/webp',
    };
};

const loadBitmap = async (file) => {
    if (typeof createImageBitmap === 'function') {
        try {
            // `from-image` aplica a orientação do EXIF durante a decodificação.
            return await createImageBitmap(file, { imageOrientation: 'from-image' });
        } catch (error) {
            // Alguns navegadores recusam a opção; o fallback abaixo cobre.
        }
    }

    return new Promise((resolve, reject) => {
        const url = URL.createObjectURL(file);
        const image = new Image();

        image.onload = () => {
            URL.revokeObjectURL(url);
            resolve(image);
        };

        image.onerror = () => {
            URL.revokeObjectURL(url);
            reject(new Error('Não foi possível decodificar a imagem.'));
        };

        image.src = url;
    });
};

const canvasToBlob = (canvas, mime, quality) => new Promise((resolve) => {
    canvas.toBlob((blob) => resolve(blob), mime, quality);
});

const renameTo = (name, extension) => {
    const base = name.includes('.') ? name.slice(0, name.lastIndexOf('.')) : name;

    return `${base}.${extension}`;
};

/**
 * Devolve um `File` menor ou `null` quando não há ganho.
 */
const optimizeFile = async (file, config) => {
    const bitmap = await loadBitmap(file);
    const width = bitmap.width || bitmap.naturalWidth || 0;
    const height = bitmap.height || bitmap.naturalHeight || 0;

    if (width < 1 || height < 1) {
        bitmap.close?.();

        return null;
    }

    const scale = Math.min(
        1,
        config.maxWidth > 0 ? config.maxWidth / width : 1,
        config.maxHeight > 0 ? config.maxHeight / height : 1,
        config.maxPixels > 0 ? Math.sqrt(config.maxPixels / (width * height)) : 1,
    );

    const targetWidth = Math.max(1, Math.round(width * scale));
    const targetHeight = Math.max(1, Math.round(height * scale));

    const canvas = document.createElement('canvas');
    canvas.width = targetWidth;
    canvas.height = targetHeight;

    const context = canvas.getContext('2d');

    if (!context) {
        bitmap.close?.();

        return null;
    }

    context.imageSmoothingEnabled = true;
    context.imageSmoothingQuality = 'high';
    context.drawImage(bitmap, 0, 0, targetWidth, targetHeight);
    bitmap.close?.();

    const blob = await canvasToBlob(canvas, config.format, config.quality / 100);

    if (!blob || blob.size >= file.size) {
        return null;
    }

    const extension = config.format === 'image/png' ? 'png' : 'webp';

    return new File([blob], renameTo(file.name, extension), {
        type: config.format,
        lastModified: file.lastModified,
    });
};

const statusElement = (input) => {
    const existing = input.parentElement?.querySelector('[data-image-upload-status]');

    if (existing) {
        return existing;
    }

    const status = document.createElement('div');
    status.className = 'form-text text-body-secondary';
    status.dataset.imageUploadStatus = 'true';
    status.setAttribute('aria-live', 'polite');

    const hint = input.parentElement?.querySelector('[data-image-upload-hint]');

    if (hint) {
        hint.insertAdjacentElement('afterend', status);
    } else {
        input.insertAdjacentElement('afterend', status);
    }

    return status;
};

const submitButtons = (input) => {
    const form = input.form ?? input.closest('form');

    return form ? Array.from(form.querySelectorAll('button[type="submit"], input[type="submit"]')) : [];
};

const setup = (input) => {
    const status = statusElement(input);
    const config = readConfig(input);
    let runId = 0;

    input.addEventListener('change', async () => {
        const file = input.files?.[0];
        const currentRun = ++runId;

        status.textContent = '';

        if (!file || !SUPPORTED_TYPES.has(file.type) || file.size <= OPTIMIZE_THRESHOLD_BYTES) {
            return;
        }

        if (typeof DataTransfer !== 'function') {
            return;
        }

        const buttons = submitButtons(input);
        buttons.forEach((button) => { button.disabled = true; });
        status.textContent = `Otimizando ${formatBytes(file.size)}…`;

        try {
            const optimized = await optimizeFile(file, config);

            // Outro arquivo foi escolhido enquanto este era processado.
            if (currentRun !== runId) {
                return;
            }

            if (!optimized) {
                status.textContent = 'A imagem já está otimizada.';

                return;
            }

            const transfer = new DataTransfer();
            transfer.items.add(optimized);
            input.files = transfer.files;

            status.textContent = `Imagem otimizada de ${formatBytes(file.size)} para ${formatBytes(optimized.size)}.`;

            if (config.maxBytes > 0 && optimized.size > config.maxBytes) {
                status.textContent += ` Ainda acima do limite de ${formatBytes(config.maxBytes)}; reduza a resolução antes de enviar.`;
            }
        } catch (error) {
            if (currentRun === runId) {
                status.textContent = 'Não foi possível otimizar a imagem aqui; o servidor fará o ajuste.';
            }
        } finally {
            if (currentRun === runId) {
                buttons.forEach((button) => { button.disabled = false; });
            }
        }
    });
};

document.addEventListener('DOMContentLoaded', () => {
    document.querySelectorAll('input[data-image-upload]').forEach(setup);
});

