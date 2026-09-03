import { Form, Head, Link } from '@inertiajs/react';
import { ArrowLeft, Download, FileSpreadsheet, Upload } from 'lucide-react';
import InputError from '@/components/input-error';
import MasterPageHeader from '@/components/master-page-header';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';

export default function StaffImport() {
    return (
        <>
            <Head title="スタッフ初期移行" />
            <div className="flex h-full flex-1 flex-col gap-6 p-4 md:p-6">
                <MasterPageHeader
                    title="スタッフ初期移行"
                    description="CSVまたはExcelから、スタッフと期間付きマスタをまとめて新規登録します。"
                    actions={
                        <Button variant="outline" asChild>
                            <Link href="/staffs">
                                <ArrowLeft />
                                スタッフ一覧へ
                            </Link>
                        </Button>
                    }
                />

                <div className="grid gap-6 xl:grid-cols-[minmax(0,1fr)_minmax(300px,0.65fr)]">
                    <section className="border-border bg-card rounded-xl border p-5 shadow-sm md:p-6">
                        <div className="flex items-start gap-3">
                            <span className="bg-primary/10 text-primary flex size-10 shrink-0 items-center justify-center rounded-lg">
                                <FileSpreadsheet className="size-5" />
                            </span>
                            <div>
                                <h2 className="font-semibold">
                                    インポートファイル
                                </h2>
                                <p className="text-muted-foreground mt-1 text-sm">
                                    最大5MB・2,000行まで。1件でもエラーがある場合は全件を登録しません。
                                </p>
                            </div>
                        </div>

                        <Form
                            action="/staffs-import"
                            method="post"
                            resetOnSuccess
                            className="mt-6 space-y-5"
                        >
                            {({ processing, errors }) => (
                                <>
                                    <div className="grid gap-2">
                                        <Label htmlFor="staff-import-file">
                                            CSV／XLSXファイル
                                        </Label>
                                        <input
                                            id="staff-import-file"
                                            name="file"
                                            type="file"
                                            accept=".csv,.xlsx,text/csv,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet"
                                            required
                                            className="border-input file:bg-primary file:text-primary-foreground hover:file:bg-primary/90 h-11 w-full rounded-md border bg-transparent px-3 py-2 text-sm file:mr-3 file:rounded-md file:border-0 file:px-3 file:py-1 file:text-sm file:font-medium"
                                        />
                                        <InputError message={errors.file} />
                                    </div>
                                    <Button disabled={processing}>
                                        <Upload />
                                        {processing
                                            ? '検証・登録中…'
                                            : '検証してインポート'}
                                    </Button>
                                </>
                            )}
                        </Form>
                    </section>

                    <aside className="border-border bg-card rounded-xl border p-5 shadow-sm md:p-6">
                        <h2 className="font-semibold">入力方法</h2>
                        <ol className="text-muted-foreground mt-3 list-decimal space-y-2 pl-5 text-sm">
                            <li>テンプレートをダウンロードします。</li>
                            <li>登録済み店舗名と一致するよう入力します。</li>
                            <li>
                                スタッフキーは空欄で構いません。同じスタッフを複数行にする場合だけ同一キーを入力します。
                            </li>
                            <li>
                                日付はYYYY-MM-DD／YYYY/MM/DD／Excel日付セルに対応し、扶養人数の空欄は0人になります。
                            </li>
                            <li>内容を確認してアップロードします。</li>
                        </ol>
                        <div className="bg-muted/60 mt-4 rounded-lg p-3 text-xs leading-5">
                            新規登録専用です。同じファイルを再送するとスタッフが重複します。社員のログインアカウントは、移行後にスタッフ編集画面から必要な方だけ作成してください。
                        </div>
                        <Button
                            variant="outline"
                            className="mt-5 w-full"
                            asChild
                        >
                            <a href="/staffs-import/template">
                                <Download />
                                Excelテンプレート
                            </a>
                        </Button>
                    </aside>
                </div>
            </div>
        </>
    );
}

StaffImport.layout = {
    breadcrumbs: [
        { title: 'スタッフ管理', href: '/staffs' },
        { title: '初期移行', href: '/staffs-import' },
    ],
};
