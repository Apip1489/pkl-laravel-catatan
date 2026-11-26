<?php

namespace App\Http\Controllers;

use App\Models\Catatan;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class CatatanController extends Controller
{
    public function index(Request $request)
    {
        $query = Catatan::where('user_id', auth()->id())
            ->where('is_archived', false);

        if ($request->filled('cari')) {
            $keyword = '%' . $request->cari . '%';
            $query->where(function ($q) use ($keyword) {
                $q->where('judul', 'like', $keyword)
                    ->orWhere('isi', 'like', $keyword);
            });
        }

        $urut = $request->get('urut', 'terbaru');
        $query->orderBy('created_at', $urut == 'terlama' ? 'asc' : 'desc');

        $catatan = $query->paginate(5);
        $jumlah_aktif = Catatan::where('user_id', auth()->id())->where('is_archived', false)->count();

        return view('catatan.index', compact('catatan', 'jumlah_aktif'));
    }

    public function create()
    {
        return view('catatan.create');
    }

    public function store(Request $request)
    {
        $request->validate([
            'judul' => 'required|max:255',
            'isi' => 'required',
            'lampiran' => 'nullable|file|mimes:jpg,jpeg,png,pdf,txt|max:2048'
        ]);

        $data = $request->only(['judul', 'isi']);
        $data['user_id'] = auth()->id(); // <-- SIMPAN USER YANG LOGIN

        if ($request->hasFile('lampiran')) {
            $file = $request->file('lampiran');
            $filename = time() . '_' . $file->getClientOriginalName();
            $file->storeAs('public/lampiran', $filename);
            $data['lampiran'] = $filename;
        }

        Catatan::create($data);
        return redirect('/catatan')->with('sukses', 'Catatan berhasil disimpan!');
    }
    public function show($id)
    {
        $catatan = Catatan::where('id', $id)->where('user_id', auth()->id())->firstOrFail();
        return view('catatan.show', compact('catatan'));
    }
    public function edit($id)
    {
        $catatan = Catatan::where('id', $id)->where('user_id', auth()->id())->firstOrFail();
        return view('catatan.edit', compact('catatan'));
    }

    public function update(Request $request, $id)
    {
        // Pastikan catatan milik user ini
        $catatan = Catatan::where('id', $id)->where('user_id', auth()->id())->firstOrFail();

        $request->validate([
            'judul' => 'required|max:255',
            'isi' => 'required',
            'lampiran' => 'nullable|file|mimes:jpg,jpeg,png,pdf,txt|max:2048'
        ]);

        $data = $request->only(['judul', 'isi']);

        if ($request->hasFile('lampiran')) {
            // Hapus file lama jika ada
            if ($catatan->lampiran) {
                Storage::disk('public')->delete($catatan->lampiran);
            }
            $file = $request->file('lampiran');
            $filename = time() . '_' . $file->hashName();
            $file->storeAs('lampiran', $filename, 'public');
            $data['lampiran'] = 'lampiran/' . $filename;
        }

        $catatan->update($data);
        return redirect("/catatan/{$catatan->id}")->with('sukses', 'Catatan berhasil diperbarui!');
    }

    public function destroy($id)
    {
        $catatan = Catatan::where('id', $id)->where('user_id', auth()->id())->firstOrFail();
        if ($catatan->lampiran) {
            Storage::disk('public')->delete($catatan->lampiran);
        }
        $catatan->delete();

        return redirect('/catatan')
            ->with('sukses', 'Catatan berhasil dihapus!');
    }

    public function arsip(Request $request)
    {
        $query = Catatan::where('user_id', auth()->id())
            ->where('is_archived', true);

        if ($request->filled('cari')) {
            $keyword = '%' . $request->cari . '%';
            $query->where(function ($q) use ($keyword) {
                $q->where('judul', 'like', $keyword)
                    ->orWhere('isi', 'like', $keyword);
            });
        }

        $urut = $request->get('urut', 'terbaru');
        $query->orderBy('created_at', $urut == 'terlama' ? 'asc' : 'desc');

        $catatan = $query->paginate(5);
        $jumlah_arsip = Catatan::where('user_id', auth()->id())->where('is_archived', true)->count();

        return view('catatan.arsip', compact('catatan', 'jumlah_arsip'));
    }
    public function archive($id)
    {
        $catatan = Catatan::where('id', $id)
            ->where('user_id', auth()->id())
            ->firstOrFail();
        $catatan->is_archived = true;
        $catatan->save();
        return redirect('/catatan')->with('sukses', 'Catatan telah diarsipkan.');
    }

    public function unarchive($id)
    {
        $catatan = Catatan::where('id', $id)
            ->where('user_id', auth()->id())
            ->firstOrFail();
        $catatan->is_archived = false;
        $catatan->save();
        return redirect('/catatan')->with('sukses', 'Catatan dipulihkan dari arsip.');
    }

    public function export()
    {
        $catatan = Catatan::where('user_id', auth()->id())
            ->where('is_archived', false)
            ->orderBy('created_at', 'desc')
            ->get();

        $filename = 'catatan_aktif_' . date('Y-m-d') . '.csv';

        $headers = [
            'Content-Type' => 'text/csv',
            'Content-Disposition' => 'attachment; filename="' . $filename . '"',
        ];

        $handle = fopen('php://output', 'w');
        fputcsv($handle, ['Judul', 'Isi', 'Tanggal Dibuat']);

        foreach ($catatan as $item) {
            fputcsv($handle, [
                $item->judul,
                $item->isi,
                $item->created_at->format('d F Y H:i')
            ]);
        }

        fclose($handle);

        return response()->stream(function () {}, 200, $headers);
    }

    public function importForm()
    {
        return view('catatan.import');
    }

    public function import(Request $request)
    {
        $request->validate([
            'file' => 'required|mimes:csv,txt|max:2048'
        ]);

        $file = $request->file('file');
        $handle = fopen($file->getPathname(), 'r');

        // Lewati header
        fgetcsv($handle);

        $jumlah = 0;
        while (($row = fgetcsv($handle)) !== false) {
            if (count($row) >= 2) {
                Catatan::create([
                    'user_id' => auth()->id(),
                    'judul' => $row[0],
                    'isi' => $row[1],
                    'is_archived' => false,
                    'created_at' => $row[2] ?? now(),
                    'updated_at' => now()
                ]);
                $jumlah++;
            }
        }

        fclose($handle);

        return redirect('/catatan')->with('sukses', "Berhasil mengimpor {$jumlah} catatan.");
    }
}
