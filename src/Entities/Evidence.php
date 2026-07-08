<?php

declare(strict_types=1);

namespace Milpa\Workflow\Entities;

use DateTime;
use Doctrine\ORM\Mapping as ORM;
use Milpa\Workflow\Enums\EvidenceType;
use Milpa\Support\UuidGenerator;

/**
 * Evidence entity - Archivo/enlace de evidencia adjuntado a un GatePassage.
 *
 * Cada evidencia es inmutable una vez creada: se asocia al pasaje y no cambia.
 * Puede ser un archivo local (filePath) o una URL externa (fileUrl), o ambos.
 */
#[ORM\Entity]
#[ORM\Table(name: 'workflow_evidences')]
#[ORM\Index(name: 'idx_ev_passage', columns: ['gate_passage_id'])]
#[ORM\Index(name: 'idx_ev_type', columns: ['type'])]
#[ORM\Index(name: 'idx_ev_uploader', columns: ['uploaded_by'])]
class Evidence
{
    use UuidGenerator;

    // =========================================================================
    // PROPERTIES - IDENTIFICATION
    // =========================================================================

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private int $id;

    #[ORM\Column(type: 'string', length: 36, unique: true)]
    private string $uuid;

    // =========================================================================
    // PROPERTIES - TYPE & DESCRIPTION
    // =========================================================================

    /**
     * Tipo de evidencia (brief_doc, sow_signed, qa_report, etc.).
     */
    #[ORM\Column(name: 'type', type: 'string', length: 50)]
    private string $type;

    /**
     * Titulo descriptivo de la evidencia.
     */
    #[ORM\Column(type: 'string', length: 255)]
    private string $title;

    /**
     * Descripcion detallada de la evidencia.
     */
    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $description = null;

    // =========================================================================
    // PROPERTIES - FILE
    // =========================================================================

    /**
     * Ruta al archivo en el sistema de archivos local.
     */
    #[ORM\Column(name: 'file_path', type: 'string', length: 500, nullable: true)]
    private ?string $filePath = null;

    /**
     * URL externa del archivo.
     */
    #[ORM\Column(name: 'file_url', type: 'string', length: 500, nullable: true)]
    private ?string $fileUrl = null;

    /**
     * Tipo MIME del archivo.
     */
    #[ORM\Column(name: 'mime_type', type: 'string', length: 100, nullable: true)]
    private ?string $mimeType = null;

    /**
     * Tamano del archivo en bytes.
     */
    #[ORM\Column(name: 'file_size', type: 'integer', nullable: true)]
    private ?int $fileSize = null;

    // =========================================================================
    // PROPERTIES - METADATA
    // =========================================================================

    /**
     * Datos adicionales en formato JSON.
     *
     * @var array<string, mixed>|null
     */
    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $metadata = null;

    // =========================================================================
    // PROPERTIES - TIMESTAMPS
    // =========================================================================

    #[ORM\Column(name: 'created_at', type: 'datetime')]
    private DateTime $createdAt;

    // =========================================================================
    // RELATIONS
    // =========================================================================

    /**
     * Pasaje de gate al que pertenece la evidencia.
     */
    #[ORM\ManyToOne(targetEntity: GatePassage::class, inversedBy: 'evidences')]
    #[ORM\JoinColumn(name: 'gate_passage_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private GatePassage $gatePassage;

    /**
     * Opaque principal that uploaded the evidence (e.g. "member:42"). The engine stores it as an
     * opaque string and never resolves it to an entity — the consuming product owns identity (D9).
     */
    #[ORM\Column(name: 'uploaded_by', type: 'string', length: 255)]
    private string $uploadedBy;

    // =========================================================================
    // CONSTRUCTOR
    // =========================================================================

    public function __construct()
    {
        $this->uuid = self::generateUuid();
        $this->createdAt = new DateTime();
    }

    // =========================================================================
    // GETTERS - IDENTIFICATION
    // =========================================================================

    public function getId(): int
    {
        return $this->id;
    }

    public function getUuid(): string
    {
        return $this->uuid;
    }

    // =========================================================================
    // GETTERS - TYPE & DESCRIPTION
    // =========================================================================

    /**
     * Obtiene el tipo como enum EvidenceType.
     */
    public function getEvidenceType(): EvidenceType
    {
        return EvidenceType::from($this->type);
    }

    public function getTypeValue(): string
    {
        return $this->type;
    }

    public function getTitle(): string
    {
        return $this->title;
    }

    public function getDescription(): ?string
    {
        return $this->description;
    }

    // =========================================================================
    // GETTERS - FILE
    // =========================================================================

    public function getFilePath(): ?string
    {
        return $this->filePath;
    }

    public function getFileUrl(): ?string
    {
        return $this->fileUrl;
    }

    public function getMimeType(): ?string
    {
        return $this->mimeType;
    }

    public function getFileSize(): ?int
    {
        return $this->fileSize;
    }

    // =========================================================================
    // GETTERS - METADATA
    // =========================================================================

    /**
     * @return array<string, mixed>|null
     */
    public function getMetadata(): ?array
    {
        return $this->metadata;
    }

    // =========================================================================
    // GETTERS - TIMESTAMPS
    // =========================================================================

    public function getCreatedAt(): DateTime
    {
        return $this->createdAt;
    }

    // =========================================================================
    // GETTERS - RELATIONS
    // =========================================================================

    public function getGatePassage(): GatePassage
    {
        return $this->gatePassage;
    }

    public function getUploadedBy(): string
    {
        return $this->uploadedBy;
    }

    // =========================================================================
    // SETTERS - TYPE & DESCRIPTION
    // =========================================================================

    /**
     * Sets the evidence type.
     */
    public function setType(EvidenceType $type): self
    {
        $this->type = $type->value;
        return $this;
    }

    /**
     * Sets the evidence's descriptive title.
     */
    public function setTitle(string $title): self
    {
        $this->title = $title;
        return $this;
    }

    /**
     * Sets the evidence's optional description.
     */
    public function setDescription(?string $description): self
    {
        $this->description = $description;
        return $this;
    }

    // =========================================================================
    // SETTERS - FILE
    // =========================================================================

    /**
     * Sets the local filesystem path to the evidence file.
     */
    public function setFilePath(?string $filePath): self
    {
        $this->filePath = $filePath;
        return $this;
    }

    /**
     * Sets the external URL of the evidence file.
     */
    public function setFileUrl(?string $fileUrl): self
    {
        $this->fileUrl = $fileUrl;
        return $this;
    }

    /**
     * Sets the evidence file's MIME type.
     */
    public function setMimeType(?string $mimeType): self
    {
        $this->mimeType = $mimeType;
        return $this;
    }

    /**
     * Sets the evidence file's size in bytes.
     */
    public function setFileSize(?int $fileSize): self
    {
        $this->fileSize = $fileSize;
        return $this;
    }

    // =========================================================================
    // SETTERS - METADATA
    // =========================================================================

    /**
     * Sets additional metadata for the evidence.
     *
     * @param array<string, mixed>|null $metadata
     */
    public function setMetadata(?array $metadata): self
    {
        $this->metadata = $metadata;
        return $this;
    }

    // =========================================================================
    // SETTERS - RELATIONS
    // =========================================================================

    /**
     * Associates this evidence with the gate passage it belongs to.
     */
    public function setGatePassage(GatePassage $gatePassage): self
    {
        $this->gatePassage = $gatePassage;
        return $this;
    }

    /**
     * Sets the opaque principal that uploaded the evidence (e.g. "member:42"); never
     * resolved to an entity by the engine — the consuming product owns identity (D9).
     */
    public function setUploadedBy(string $uploadedBy): self
    {
        $this->uploadedBy = $uploadedBy;
        return $this;
    }

    // =========================================================================
    // DOMAIN METHODS
    // =========================================================================

    /**
     * Verifica si la evidencia tiene un archivo local.
     */
    public function hasFile(): bool
    {
        return $this->filePath !== null;
    }

    /**
     * Verifica si la evidencia tiene una URL externa.
     */
    public function hasUrl(): bool
    {
        return $this->fileUrl !== null;
    }

    /**
     * Verifica si el archivo es una imagen.
     */
    public function isImage(): bool
    {
        return $this->mimeType !== null && str_starts_with($this->mimeType, 'image/');
    }

    /**
     * Obtiene el tamano del archivo formateado.
     */
    public function getFormattedFileSize(): string
    {
        if ($this->fileSize === null) {
            return '0 B';
        }

        $bytes = $this->fileSize;

        if ($bytes >= 1048576) {
            return round($bytes / 1048576, 2) . ' MB';
        }

        if ($bytes >= 1024) {
            return round($bytes / 1024, 2) . ' KB';
        }

        return $bytes . ' B';
    }

    // =========================================================================
    // SERIALIZATION
    // =========================================================================

    /**
     * Converts the entity to an array for API responses.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'uuid' => $this->uuid,
            'type' => $this->type,
            'type_label' => $this->getEvidenceType()->label(),
            'title' => $this->title,
            'description' => $this->description,
            'file' => [
                'path' => $this->filePath,
                'url' => $this->fileUrl,
                'mime_type' => $this->mimeType,
                'size' => $this->fileSize,
                'size_formatted' => $this->getFormattedFileSize(),
                'is_image' => $this->isImage(),
                'has_file' => $this->hasFile(),
                'has_url' => $this->hasUrl(),
            ],
            'gate_passage_id' => $this->gatePassage->getId(),
            'uploaded_by' => $this->uploadedBy,
            'metadata' => $this->metadata,
            'created_at' => $this->createdAt->format('Y-m-d H:i:s'),
        ];
    }
}
