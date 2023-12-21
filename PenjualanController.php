<?php

namespace App\Http\Controllers;

use Session;
use App\Helper;
use App\Models\MdAkun;
use App\Models\MdBarang;
use App\Models\Penjualan;
use App\Models\JurnalUmum;
use Illuminate\Support\Str;
use Illuminate\Http\Request;
use App\Models\MdBarangDetil;
use App\Models\PenjualanBayar;
use App\Models\PenjualanDetil;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Auth;
use Yajra\DataTables\Facades\DataTables;

class PenjualanController extends Controller
{

    /**
     * Create a new controller instance.
     *
     * @return void
     */
    public function __construct()
    {
        $this->middleware('auth');
    }

    /**
     * Display a listing of the resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function index()
    {
        return view('admin.penjualan.index');
    }

    public function dt()
    {
        $data = Penjualan::where('idstatus',0)->get();
        return DataTables::of($data)
        ->addColumn('action', function($data) {
            return '
            <a class="btn btn-sm btn-icon btn-outline-info waves-effect waves-float waves-light" href="'.route('penjualan.detail', $data->id).'">Detail</a>';
        })

        ->addColumn('sisa', function($data) {
        return number_format($data->sisa,0,'.','.');
        })


        ->make(true);
    }

    public function create()
    {
      $kas = MdAkun::where('akun','like','1200%')->get();
      return view('admin.penjualan.create', compact('kas'));
    }

    public function getnomor($tanggal)
    {
        $tanggal = date_create($tanggal);
        $bulan = date_format($tanggal,'m');
        $nourut = Penjualan::wheremonth('tgl',$bulan)->count();
        $nourut = str_pad($nourut+1, 5, '0', STR_PAD_LEFT);
        $nomor = 'KS/'.date('y/'.$bulan.'/').$nourut;

        return $nomor;
    }

    public function getproduk($id)
    {
        $data = MdBarang::where('id',$id)->first();
        return $data;
    }

    public function store(Request $request)
    {
        $penjualan = Penjualan::create([
        'nomor' => $request->nomor,
        'customer' => $request->customer,
        'tgl' => $request->tanggal,
        'subtotal' => $request->subtotal,
        'total' => $request->total,
        'diskon' => $request->diskon,
        'diskonpersen' => $request->diskonpersen,
        'diskonretur' => $request->diskonretur,
        'terbayar' => 0,
        'sisa' => $request->total + $request->ongkir,
        'memo' => $request->memo,
        ]);

        $jual = 0;
        $persediaan = 0;

        $tabel ='';
        $total = 0;
        foreach ($request->datas as $d) {
        $total+=Helper::ubahRpDatabase($d['Rate'])*$d['Jumlah'];
        $barang = MdBarang::where('id',$d['Kode'])->first();
        $rate = preg_replace('/\D/', '', $d['Rate']);

        $tabel.='<tr>
        <td>'.$d['Jumlah'].' x '.number_format($rate,0,".",".").'</td>
        <td rowspan =2>'.number_format($rate*$d['Jumlah'],0,".",".").'</td>
        </tr>
        <tr>
        <td>'.$barang->kode.' - '.$barang->nama.'</td>
        </tr>'
        ;
        $barang = MdBarang::where('id',$d['Kode'])->first();
        $penjualandetil = PenjualanDetil::create([
            'idpenjualan' => $penjualan->id,
            'idbarang' => $d['Kode'],
            'qty' => $d['Jumlah'],
            'hpp' => $barang->hargabeli,
            'hargajual' =>  Helper::ubahRpDatabase($d['Rate']),
        ]);


        MdBarangDetil::create([
            'tanggal' => $request->tanggal,
            'idbarang' => $d['Kode'],
            'qty' => 0- $d['Jumlah'],
            'idtransaksi' => $penjualandetil->id,
            'idstatus' => 2,
            'keterangan' => 'Penjualan'
        ]);

        MdBarang::where('id',$d['Kode'])->update([
            'stok' => $barang->stok - $d['Jumlah']
        ]);

        $persediaan+= $barang->hargabeli * $d['Jumlah'];
        }

        $tabel.='<tr><td>SUB TOTAL</td><td>'.number_format($total,0,'.','.').'</td></tr>';
        $diskontotal = $request->diskon;
        if ($diskontotal > 0) {
        $tabel.='<tr><td>DISKON</td><td style="text-align:"left"">'.number_format($diskontotal,0,'.','.').'</td></tr>';
        }
        $grand = $total - $diskontotal;
        $tabel.='<tr><td>GRAND TOTAL</td><td style="text-align:"left"">'.number_format($grand,0,'.','.').'</td></tr>';


        //cek bayar
        $bayar = preg_replace('/\D/', '',$request->bayar);
        $tabel.='<tr><td>BAYAR</td><td>'.number_format($bayar,0,'.','.').'</td></tr>';

        if ($grand - $bayar > 0) {
        $sisatagihan = $grand - $bayar;
        $tabel.='<tr><td>SISA TAGIHAN</td><td>'.number_format($sisatagihan,0,'.','.').'</td></tr>';
        }

        if ($request->kembalian > 0) {
        $tabel.='<tr><td style="text-align:"left"">KEMBALI</td><td>'.number_format($request->kembalian,0,'.','.').'</td></tr>';

        }

        if ($bayar > $request->total) {
        $bayar = $request->total;
        }


        if ($bayar > 0) {
        $sisa = $request->total - $bayar + $request->ongkir;
        Penjualan::where('id',$penjualan->id)->update([
            'sisa' => $sisa,
            'terbayar' => $bayar
        ]);

        if ($request->metodebayar == 1) {
            $norefrensi = Str::random(15);
        }else {
            $norefrensi = $request->noreff;
        }

        PenjualanBayar::create([
            'idpenjualan' => $penjualan->id,
            'nomor' => Str::random(15),
            'metode' => $request->metodebayar,
            'norefrensi' => $norefrensi,
            'tgl' => $request->tanggal,
            'bayar' => $bayar,
            'iduser' => auth::user()->id
        ]);
        // kas
        JurnalUmum::create([
            'kodeakun' => $request->idakunbank,
            'tahun' => date('Y', strtotime($request->tanggal)),
            'bulan' => date('m', strtotime($request->tanggal)),
            'tanggal' => $request->tanggal,
            'jenis' => 5,
            'debet' => $bayar,
            'keterangan' => 'Pembayaran Penjualan '.$request->nomor,
            'idtransaksi' => $penjualan->id,
            'iduser' => auth::user()->id
        ]);

        if ($sisa > 0) {
            // piutang +
            JurnalUmum::create([
            'kodeakun' => 12101,
            'tahun' => date('Y', strtotime($request->tanggal)),
            'bulan' => date('m', strtotime($request->tanggal)),
            'tanggal' => $request->tanggal,
            'jenis' => 5,
            'debet' => $sisa,
            'keterangan' => 'Piutang Penjualan '.$request->nomor,
            'idtransaksi' => $penjualan->id,
            'iduser' => auth::user()->id
            ]);
        }
        }else {
        // piutang +
        JurnalUmum::create([
            'kodeakun' => 12101,
            'tahun' => date('Y', strtotime($request->tanggal)),
            'bulan' => date('m', strtotime($request->tanggal)),
            'tanggal' => $request->tanggal,
            'jenis' => 5,
            'debet' => $request->total,
            'keterangan' => 'Piutang Penjualan '.$request->nomor,
            'idtransaksi' => $penjualan->id,
            'iduser' => auth::user()->id
        ]);
        }

        // penjualan +
        JurnalUmum::create([
        'kodeakun' => 41001,
        'tahun' => date('Y', strtotime($request->tanggal)),
        'bulan' => date('m', strtotime($request->tanggal)),
        'tanggal' => $request->tanggal,
        'jenis' => 5,
        'kredit' => $request->total + $request->diskon,
        'keterangan' => 'Penjualan '.$request->nomor,
        'idtransaksi' => $penjualan->id,
        'iduser' => auth::user()->id
        ]);


        if ($request->diskon > 0) {
        // potongan +
        JurnalUmum::create([
            'kodeakun' => 41003,
            'tahun' => date('Y', strtotime($request->tanggal)),
            'bulan' => date('m', strtotime($request->tanggal)),
            'tanggal' => $request->tanggal,
            'jenis' => 5,
            'debet' => $request->diskon,
            'keterangan' => 'Potongan Penjualan '.$request->nomor,
            'idtransaksi' => $penjualan->id,
            'iduser' => auth::user()->id
        ]);

        }


        // persediaan -
        JurnalUmum::create([
        'kodeakun' => 12201,
        'tahun' => date('Y', strtotime($request->tanggal)),
        'bulan' => date('m', strtotime($request->tanggal)),
        'tanggal' => $request->tanggal,
        'jenis' => 5,
        'kredit' => $persediaan,
        'keterangan' => 'Persediaan '.$request->nomor,
        'idtransaksi' => $penjualan->id,
        'iduser' => auth::user()->id
        ]);

        // hpp +
        JurnalUmum::create([
        'kodeakun' => 12401,
        'tahun' => date('Y', strtotime($request->tanggal)),
        'bulan' => date('m', strtotime($request->tanggal)),
        'tanggal' => $request->tanggal,
        'jenis' => 5,
        'debet' => $persediaan,
        'keterangan' => 'hpp Penjualan '.$request->nomor,
        'idtransaksi' => $penjualan->id,
        'iduser' => auth::user()->id
        ]);

        Session::flash('notif', json_encode([
        'title' => "TRANSAKSI PENJUALAN",
        'text' => "Berhasil Melakukan Penjualan",
        'type' => "success"
        ]));


        return [$tabel,$request->datas,$penjualan];
    }

    public function detail($id)
    {
        $data = Penjualan::with(['detil.barang'])->where('id',$id)->first();
        if ($data == null) {
        abort('404');
        }
        $kas = MdAkun::where('akun','like','1200%')->get();

        $tabel='';
        $subtotal = 0;
        $detil = PenjualanDetil::with(['barang'])->where('idpenjualan',$data->id)->get();
        foreach ($detil as $d) {
        $subtotal+=$d->qty*$d->hargajual;
        $tabel.='<tr>
        <td>'.$d->qty.' x '.number_format($d->hargajual,0,'.','.').'</td>
        <td rowspan="2">'.number_format($d->hargajual*$d->qty,0,'.','.').'</td>
        </tr>
        <tr>
        <td>'.$d->barang->kode.' - '.$d->barang->nama.'</td>
        </tr>'
        ;
        }
        $tabel.='<tr><td>SUB TOTAL</td><td style="text-align:left">'.number_format($subtotal,0,'.','.').'</td></tr>';

        $diskontotal = $data->diskon ;
        if($diskontotal > 0) {
            $tabel.='<tr><td>DISKON</td><td style="text-align:left">'.number_format($diskontotal,0,'.','.').'</td></tr>';
        }
        $tabel.='<tr><td>GRAND TOTAL</td><td style="text-align:left">'.number_format(($data->total),0,'.','.').'</td></tr>';
        $tabel.='<tr><td>BAYAR</td><td style="text-align:left">'.number_format($data->terbayar,0,'.','.').'</td></tr>';
        $tabel.='<tr><td>SISA TAGIHAN</td><td style="text-align:left">'.number_format($data->sisa,0,'.','.').'</td></tr>';

        $tabel = json_encode($tabel);
        // dd($tabel);

        return view('admin.penjualan.detail', compact('data','kas','tabel'));
    }

    public function dtdetail($id)
    {
        $data = PenjualanBayar::where('idpenjualan',$id)->get();
        return DataTables::of($data)
        ->make(true);
    }

    public function update(Request $request)
    {
        $persediaan = 0;
        $harga = 0;
        foreach ($request->datas as $d) {
        $detil = MdBarangDetil::where('idbarang',$d['idbarang'])->orderby('id','desc')->first();
        MdBarangDetil::create([
            'tanggal' => $request->tanggal,
            'idbarang' => $d['idbarang'],
            'qty' => 0- $d['qty'],
            'idstatus' => 2
        ]);

        $barang = MdBarang::where('id', $d['idbarang'])->first();
        MdBarang::where('id',$d['idbarang'])->update([
            'stok' => $barang->stok - $d['qty']
        ]);

        $persediaan+=$barang->hargabeli * $d['qty'];
        $harga+=$d['qty'] * Helper::ubahRpDatabase($d['hargajual']);

        // add ke detil penjualan
        $penjualandetil = PenjualanDetil::create([
            'idpenjualan' => $request->idpenjualan,
            'idbarang' => $d['idbarang'],
            'qty' => $d['qty'],
            'hpp' => $barang->hpp,
            'hargajual' =>  Helper::ubahRpDatabase($d['hargajual']),
        ]);
        }

        $penjualan = Penjualan::where('id',$request->idpenjualan)->first();
        // 1. update total
        // 2. update sisa
        Penjualan::where('id',$request->idpenjualan)->update([
        'subtotal' => $penjualan->subtotal + $harga,
        'total' => $penjualan->total + $harga,
        'sisa' => $penjualan->sisa + $harga
        ]);

        // jurnal
        // 4. piutang
        JurnalUmum::create([
        'kodeakun' => 12101,
        'tahun' => date('Y'),
        'bulan' => date('m'),
        'tanggal' => date('Y-m-d'),
        'jenis' => 5,
        'debet' => $harga,
        'keterangan' => 'Piutang Penjualan '.$penjualan->nomor,
        'idtransaksi' => $penjualan->id,
        'iduser' => auth::user()->id
        ]);
        // 5. penjualan

        JurnalUmum::create([
        'kodeakun' => 41001,
        'tahun' => date('Y'),
        'bulan' => date('m'),
        'tanggal' => date('Y-m-d'),
        'jenis' => 5,
        'kredit' => $harga,
        'keterangan' => 'Penjualan '.$request->nomor,
        'idtransaksi' => $penjualan->id,
        'iduser' => auth::user()->id
        ]);

        // persediaan -
        JurnalUmum::create([
        'kodeakun' => 12201,
        'tahun' => date('Y'),
        'bulan' => date('m'),
        'tanggal' => date('Y-m-d'),
        'jenis' => 5,
        'kredit' => $persediaan,
        'keterangan' => 'Persediaan '.$request->nomor,
        'idtransaksi' => $penjualan->id,
        'iduser' => auth::user()->id
        ]);

        // hpp +
        JurnalUmum::create([
        'kodeakun' => 12401,
        'tahun' => date('Y'),
        'bulan' => date('m'),
        'tanggal' => date('Y-m-d'),
        'jenis' => 5,
        'debet' => $persediaan,
        'keterangan' => 'hpp Penjualan '.$request->nomor,
        'idtransaksi' => $penjualan->id,
        'iduser' => auth::user()->id
        ]);

        Session::flash('notif', json_encode([
        'title' => "TRANSAKSI PENJUALAN",
        'text' => "Berhasil Menambah Produk Penjualan",
        'type' => "success"
        ]));

        return $request->datas;
    }

    public function storebayar(Request $request)
    {
        // dd($request);
        DB::beginTransaction();
        try {
        $data = Penjualan::where('id',$request->idpenjualan)->first();
        PenjualanBayar::create([
            'idpenjualan' => $request->idpenjualan,
            'bayar' => Helper::ubahRpDatabase($request->bayar),
            'tgl' => $request->tgl,
            'memo' => $request->memo,
            'metode' => $request->metode,
            'norefrensi' => $request->norefrensi,
            'nomor' => $request->nomor,
        ]);

        $data = Penjualan::where('id',$request->idpenjualan)->first();
        $sisa = $data->sisa - Helper::ubahRpDatabase($request->bayar);
        $terbayar = Helper::ubahRpDatabase($request->bayar) + $data->terbayar;


        Penjualan::where('id',$request->idpenjualan)->update([
            'terbayar' => $terbayar,
            'sisa' => $sisa
        ]);

        if ($request->metode == 1) {
            $noakun = $request->noakun1;
        } else {
            $noakun = $request->noakun2;
        }


        //piutang
        JurnalUmum::create([
            'kodeakun' => 12101,
            'tahun' => date('Y', strtotime($request->tgl)),
            'bulan' => date('m', strtotime($request->tgl)),
            'tanggal' => $request->tgl,
            'jenis' => 8,
            'kredit' => Helper::ubahRpDatabase($request->bayar),
            'keterangan' => 'Pembayaran '.$data->nomor,
            'idtransaksi' => $data->id,
            'iduser' => auth::user()->id
        ]);


        JurnalUmum::create([
            'kodeakun' => $noakun,
            'tahun' => date('Y', strtotime($request->tgl)),
            'bulan' => date('m', strtotime($request->tgl)),
            'tanggal' => $request->tgl,
            'jenis' => 8,
            'debet' => Helper::ubahRpDatabase($request->bayar),
            'keterangan' => 'Pembayaran '.$data->nomor,
            'idtransaksi' => $data->id,
            'iduser' => auth::user()->id
        ]);

        DB::commit();
        return back()->with('notif', json_encode([
            'title' => "PEMBAYARAN",
            'text' => "Berhasil melakukan pembayaran",
            'type' => "success"
        ]));
        } catch (\Exception $e) {
            DB::rollBack();
            return back()->with('notif', json_encode([
                'title' => "ERROR",
                'text' => "Gagal melakukan pembayaran",
                'type' => "error"
            ]));
        }

    }

}
