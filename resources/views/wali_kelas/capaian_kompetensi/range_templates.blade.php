{{-- resources/views/wali_kelas/capaian_kompetensi/range_templates.blade.php --}}
@extends('layouts.wali_kelas.app')

@section('content')
<div class="p-4 bg-white mt-14" x-data="rangeTemplateManager()">
    <!-- Header -->
    <div class="mb-6 flex flex-col items-start gap-4 md:flex-row md:items-center md:justify-between">
        <div>
            <h2 class="text-2xl font-bold text-green-700">Kelola Template Range Capaian Kompetensi</h2>
            <p class="text-sm text-gray-600 mt-1">
                Tahun Ajaran: {{ $tahunAjaran->tahun_ajaran }} - Semester {{ $tahunAjaran->semester }}
            </p>
        </div>
        <div class="flex flex-wrap gap-3 md:justify-end">
            <button @click="resetToDefault" 
                    class="px-4 py-2 bg-orange-500 text-white rounded-lg hover:bg-orange-600">
                Reset ke Default
            </button>
            <a href="{{ route('wali_kelas.capaian_kompetensi.index') }}" 
               class="px-4 py-2 bg-gray-500 text-white rounded-lg hover:bg-gray-600">
                Kembali
            </a>
        </div>
    </div>

    <!-- Info Section -->
    <div class="bg-blue-50 border-l-4 border-blue-400 p-4 mb-6">
        <div class="flex">
            <div class="flex-shrink-0">
                <svg class="h-5 w-5 text-blue-400" fill="currentColor" viewBox="0 0 20 20">
                    <path fill-rule="evenodd" d="M18 10a8 8 0 11-16 0 8 8 0 0116 0zm-7-4a1 1 0 11-2 0 1 1 0 012 0zM9 9a1 1 0 000 2v3a1 1 0 001 1h1a1 1 0 100-2v-3a1 1 0 00-1-1H9z" clip-rule="evenodd"></path>
                </svg>
            </div>
            <div class="ml-3">
                <p class="text-sm text-blue-700">
                    <strong>Petunjuk:</strong> Kelola template kalimat untuk setiap range nilai. 
                    Gunakan placeholder <code>{nama_siswa}</code> dan <code>{mata_pelajaran}</code> 
                    yang akan diganti otomatis saat generate capaian kompetensi.
                </p>
            </div>
        </div>
    </div>

    <!-- Templates List -->
    <div class="space-y-4">
        <template x-for="(template, index) in templates" :key="template.id">
            <div class="bg-white border border-gray-200 rounded-lg p-6 shadow-sm">
                <!-- Template Header -->
                <div class="flex items-center justify-between mb-4">
                    <div class="flex items-center space-x-3">
                        <div class="h-3 w-3 rounded-full" 
                             :class="template.color_class || 'bg-gray-400'"></div>
                        <input type="text" 
                               x-model="template.nama_range"
                               class="text-lg font-semibold bg-transparent border-none focus:ring-0 focus:outline-none"
                               placeholder="Nama Range">
                    </div>
                    
                    <div class="flex items-center space-x-2">
                        <label class="flex items-center">
                            <input type="checkbox" 
                                   x-model="template.is_active"
                                   class="mr-2 text-green-600 rounded focus:ring-green-500">
                            <span class="text-sm text-gray-600">Aktif</span>
                        </label>
                        
                        <button @click="removeTemplate(index)" 
                                class="text-red-600 hover:text-red-800">
                            <svg class="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"></path>
                            </svg>
                        </button>
                    </div>
                </div>

                <!-- Range Values -->
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mb-4">
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Nilai Minimum</label>
                        <input type="number" 
                               x-model.number="template.nilai_min"
                               min="0" max="100"
                               class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-green-500 focus:border-green-500">
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Nilai Maksimum</label>
                        <input type="number" 
                               x-model.number="template.nilai_max"
                               min="0" max="100"
                               class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-green-500 focus:border-green-500">
                    </div>
                </div>

                <!-- Range Display -->
                <div class="mb-4">
                    <div class="inline-flex items-center px-3 py-1 rounded-full text-sm font-medium bg-gray-100 text-gray-800">
                        Range: <span x-text="template.nilai_min"></span> - <span x-text="template.nilai_max"></span>
                    </div>
                </div>

                <!-- Template Text -->
                <div class="mb-4">
                    <label class="block text-sm font-medium text-gray-700 mb-2">
                        Template Kalimat
                        <span class="text-xs text-gray-500">(Gunakan {nama_siswa} dan {mata_pelajaran})</span>
                    </label>
                    <textarea x-model="template.template_text"
                              rows="3"
                              class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-green-500 focus:border-green-500"
                              placeholder="Contoh: {nama_siswa} menunjukkan penguasaan yang sangat baik dalam {mata_pelajaran}..."></textarea>
                </div>

                <!-- Preview Section -->
                <div class="bg-gray-50 rounded-lg p-4">
                    <h4 class="text-sm font-medium text-gray-700 mb-2">Preview:</h4>
                    <p class="text-sm text-gray-600" 
                       x-text="generatePreview(template.template_text)"></p>
                </div>
            </div>
        </template>
    </div>

    <!-- Add New Template Button -->
    <div class="mt-6">
        <button @click="addNewTemplate" 
                class="w-full border-2 border-dashed border-gray-300 rounded-lg p-4 text-gray-500 hover:border-green-500 hover:text-green-500 transition-colors">
            <svg class="h-6 w-6 mx-auto mb-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6v6m0 0v6m0-6h6m-6 0H6"></path>
            </svg>
            <span class="text-sm">Tambah Template Range Baru</span>
        </button>
    </div>

    <!-- Save Button -->
    <div class="mt-6 flex justify-end space-x-3">
        <button @click="saveTemplates" 
                :disabled="isSaving"
                :class="isSaving ? 'opacity-50 cursor-not-allowed' : ''"
                class="px-6 py-2 bg-green-600 text-white rounded-lg hover:bg-green-700">
            <span x-show="!isSaving">Simpan Template</span>
            <span x-show="isSaving" class="flex items-center">
                <svg class="animate-spin -ml-1 mr-2 h-4 w-4 text-white" fill="none" viewBox="0 0 24 24">
                    <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                    <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path>
                </svg>
                Menyimpan...
            </span>
        </button>
    </div>
</div>

@push('scripts')
<script>
function rangeTemplateManager() {
    return {
        templates: @json($templates),
        isSaving: false,
        
        init() {
            console.log('Range Template Manager initialized with templates:', this.templates);
        },
        
        generatePreview(templateText) {
            return templateText
                .replace(/{nama_siswa}/g, 'Ahmad Rizki')
                .replace(/{mata_pelajaran}/g, 'Matematika');
        },
        
        addNewTemplate() {
            const newTemplate = {
                id: null, // Will be assigned after save
                nama_range: 'Range Baru',
                nilai_min: 0,
                nilai_max: 100,
                template_text: '{nama_siswa} menunjukkan kemampuan dalam {mata_pelajaran}.',
                color_class: 'bg-gray-400',
                is_active: true,
                urutan: this.templates.length + 1
            };
            
            this.templates.push(newTemplate);
        },
        
        removeTemplate(index) {
            if (confirm('Apakah Anda yakin ingin menghapus template ini?')) {
                if (this.templates[index].id) {
                    // If template has ID, call delete API
                    this.deleteTemplate(this.templates[index].id, index);
                } else {
                    // If new template, just remove from array
                    this.templates.splice(index, 1);
                }
            }
        },
        
async deleteTemplate(templateId, index) {
    try {
        const response = await fetch(`/wali-kelas/capaian-kompetensi/range-templates/${templateId}`, {
            method: 'DELETE',
            headers: {
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').getAttribute('content'),
                'Content-Type': 'application/json',
                'Accept': 'application/json'
            }
        });

        const result = await response.json();
        
        if (result.success) {
            this.templates.splice(index, 1);
            this.showAlert('success', result.message);
        } else {
            this.showAlert('error', result.message);
        }
    } catch (error) {
        console.error('Error deleting template:', error);
        this.showAlert('error', 'Terjadi kesalahan saat menghapus template');
    }
},

async saveTemplates() {
    if (this.isSaving) return;
    
    // Validate templates
    for (let i = 0; i < this.templates.length; i++) {
        const template = this.templates[i];
        
        if (!template.nama_range.trim()) {
            this.showAlert('error', `Nama range pada template ${i + 1} tidak boleh kosong`);
            return;
        }
        
        if (template.nilai_min > template.nilai_max) {
            this.showAlert('error', `Nilai minimum tidak boleh lebih besar dari maksimum pada template "${template.nama_range}"`);
            return;
        }
        
        if (!template.template_text.trim()) {
            this.showAlert('error', `Template kalimat pada "${template.nama_range}" tidak boleh kosong`);
            return;
        }
    }
    
    this.isSaving = true;
    
    try {
        const response = await fetch('/wali-kelas/capaian-kompetensi/range-templates', {
            method: 'PUT',
            headers: {
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').getAttribute('content'),
                'Content-Type': 'application/json',
                'Accept': 'application/json'
            },
            body: JSON.stringify({
                templates: this.templates.filter(t => t.id) // Only send existing templates
            })
        });

        const result = await response.json();
        
        if (result.success) {
            // Handle new templates
            for (let template of this.templates.filter(t => !t.id)) {
                await this.createNewTemplate(template);
            }
            
            this.showAlert('success', 'Template range berhasil disimpan');
            
            // Reload page to get updated data
            setTimeout(() => {
                window.location.reload();
            }, 1500);
        } else {
            this.showAlert('error', result.message);
        }
    } catch (error) {
        console.error('Error saving templates:', error);
        this.showAlert('error', 'Terjadi kesalahan saat menyimpan template');
    } finally {
        this.isSaving = false;
    }
},

async createNewTemplate(template) {
    try {
        const response = await fetch('/wali-kelas/capaian-kompetensi/range-templates', {
            method: 'POST',
            headers: {
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').getAttribute('content'),
                'Content-Type': 'application/json',
                'Accept': 'application/json'
            },
            body: JSON.stringify(template)
        });

        const result = await response.json();
        
        if (!result.success) {
            throw new Error(result.message);
        }
        
        return result.template;
    } catch (error) {
        console.error('Error creating new template:', error);
        throw error;
    }
},

async resetToDefault() {
    if (!confirm('Apakah Anda yakin ingin mereset semua template ke pengaturan default? Data yang ada akan hilang.')) {
        return;
    }
    
    try {
        const response = await fetch('/wali-kelas/capaian-kompetensi/range-templates/reset', {
            method: 'POST',
            headers: {
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').getAttribute('content'),
                'Content-Type': 'application/json',
                'Accept': 'application/json'
            }
        });

        const result = await response.json();
        
        if (result.success) {
            this.showAlert('success', result.message);
            setTimeout(() => {
                window.location.reload();
            }, 1500);
        } else {
            this.showAlert('error', result.message);
        }
    } catch (error) {
        console.error('Error resetting templates:', error);
        this.showAlert('error', 'Terjadi kesalahan saat mereset template');
    }
},

showAlert(type, message) {
    // Use SweetAlert2 if available, otherwise use browser alert
    if (typeof Swal !== 'undefined') {
        Swal.fire({
            icon: type === 'success' ? 'success' : 'error',
            title: type === 'success' ? 'Berhasil' : 'Error',
            text: message,
            timer: 3000,
            showConfirmButton: false
        });
    } else {
        alert(message);
    }
}
