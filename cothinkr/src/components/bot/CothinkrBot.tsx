'use client';

import React, { useState, useRef, useEffect } from 'react';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { ScrollArea } from '@/components/ui/scroll-area';
import { Badge } from '@/components/ui/badge';
import { Separator } from '@/components/ui/separator';
import { 
  Bot, 
  Send, 
  User, 
  Search, 
  FileText, 
  Target, 
  DollarSign, 
  Eye,
  Sparkles,
  X,
  Minimize2,
  Maximize2,
  RefreshCw
} from 'lucide-react';
import { cn } from '@/lib/utils';
import { useAppStore } from '@/lib/store';
import { toast } from 'sonner';

interface Message {
  id: string;
  type: 'user' | 'bot';
  content: string;
  timestamp: Date;
  sources?: SearchResult[];
  thinking?: string;
}

interface SearchResult {
  type: 'initiative' | 'project' | 'vision' | 'budget' | 'document';
  title: string;
  content: string;
  relevance: number;
  metadata?: any;
}

interface CothinkrBotProps {
  className?: string;
  defaultOpen?: boolean;
}

const CothinkrBot: React.FC<CothinkrBotProps> = ({ 
  className = '', 
  defaultOpen = false 
}) => {
  const [isOpen, setIsOpen] = useState(defaultOpen);
  const [isMinimized, setIsMinimized] = useState(false);
  const [messages, setMessages] = useState<Message[]>([
    {
      id: '1',
      type: 'bot',
      content: 'Hello! I\'m COTHINK\'R, your strategic planning assistant. I can help you with insights from your vision, initiatives, projects, and budget data. What would you like to explore?',
      timestamp: new Date()
    }
  ]);
  const [inputValue, setInputValue] = useState('');
  const [isLoading, setIsLoading] = useState(false);
  const [searchResults, setSearchResults] = useState<SearchResult[]>([]);
  
  const messagesEndRef = useRef<HTMLDivElement>(null);
  const inputRef = useRef<HTMLInputElement>(null);
  const { vision, initiatives, projects, budget } = useAppStore();

  const scrollToBottom = () => {
    messagesEndRef.current?.scrollIntoView({ behavior: 'smooth' });
  };

  useEffect(() => {
    scrollToBottom();
  }, [messages]);

  // RAG Search Function
  const searchContext = async (query: string): Promise<SearchResult[]> => {
    const results: SearchResult[] = [];
    const queryLower = query.toLowerCase();
    
    // Search Vision
    Object.entries(vision).forEach(([key, value]) => {
      if (value && value.toLowerCase().includes(queryLower)) {
        results.push({
          type: 'vision',
          title: `Vision: ${key.charAt(0).toUpperCase() + key.slice(1)}`,
          content: value.substring(0, 200) + '...',
          relevance: calculateRelevance(value, queryLower),
          metadata: { section: key }
        });
      }
    });

    // Search Initiatives
    initiatives.forEach(initiative => {
      const searchText = `${initiative.title} ${initiative.description}`.toLowerCase();
      if (searchText.includes(queryLower)) {
        results.push({
          type: 'initiative',
          title: initiative.title,
          content: initiative.description,
          relevance: calculateRelevance(searchText, queryLower),
          metadata: { status: initiative.status, priority: initiative.priority }
        });
      }
    });

    // Search Projects
    projects.forEach(project => {
      const searchText = `${project.name} ${project.description || ''}`.toLowerCase();
      if (searchText.includes(queryLower)) {
        results.push({
          type: 'project',
          title: project.name,
          content: project.description || 'No description available',
          relevance: calculateRelevance(searchText, queryLower),
          metadata: { status: project.status, quarter: project.quarter }
        });
      }
    });

    // Search Budget (contextual)
    if (queryLower.includes('budget') || queryLower.includes('revenue') || queryLower.includes('expense')) {
      const totalRevenue = budget.plan.reduce((sum, month) => sum + month.revenue, 0);
      const totalExpenses = budget.plan.reduce((sum, month) => sum + month.expense, 0);
      
      results.push({
        type: 'budget',
        title: 'Budget Overview',
        content: `Annual planned revenue: $${totalRevenue.toLocaleString()}, Total expenses: $${totalExpenses.toLocaleString()}`,
        relevance: 0.9,
        metadata: { totalRevenue, totalExpenses }
      });
    }

    // Sort by relevance
    return results.sort((a, b) => b.relevance - a.relevance).slice(0, 5);
  };

  const calculateRelevance = (text: string, query: string): number => {
    const words = query.split(' ');
    let score = 0;
    
    words.forEach(word => {
      if (text.includes(word)) {
        score += 0.3;
        // Bonus for exact match
        if (text.includes(query)) score += 0.4;
        // Bonus for title/beginning match
        if (text.startsWith(word)) score += 0.3;
      }
    });
    
    return Math.min(score, 1);
  };

  // Generate AI Response
  const generateResponse = async (query: string, context: SearchResult[]): Promise<string> => {
    // Simulate AI thinking time
    await new Promise(resolve => setTimeout(resolve, 1500 + Math.random() * 1000));
    
    const queryLower = query.toLowerCase();
    
    // Strategic analysis patterns
    if (queryLower.includes('strategic') || queryLower.includes('strategy')) {
      return `Based on your current strategic position, I can see several key insights:\n\n${context.map(r => `• ${r.title}: ${r.content.substring(0, 100)}...`).join('\n')}\n\nThese elements align with your P3 methodology (Prepare, Plan, Pursue). Would you like me to analyze any specific area in more detail?`;
    }
    
    if (queryLower.includes('initiative') || queryLower.includes('project')) {
      const initiativeCount = initiatives.length;
      const approvedCount = initiatives.filter(i => i.status === 'approved').length;
      return `You currently have ${initiativeCount} initiatives, with ${approvedCount} approved. ${context.length > 0 ? 'Here are the most relevant ones:\n\n' + context.map(r => `• **${r.title}**: ${r.content.substring(0, 150)}...`).join('\n\n') : ''}\n\nI recommend focusing on high-impact, low-effort initiatives first. Would you like me to analyze the priority distribution?`;
    }
    
    if (queryLower.includes('budget') || queryLower.includes('financial')) {
      const budgetData = context.find(r => r.type === 'budget');
      if (budgetData) {
        return `Your budget analysis shows:\n\n• **Planned Revenue**: $${budgetData.metadata.totalRevenue.toLocaleString()}\n• **Total Expenses**: $${budgetData.metadata.totalExpenses.toLocaleString()}\n• **Net Margin**: ${(((budgetData.metadata.totalRevenue - budgetData.metadata.totalExpenses) / budgetData.metadata.totalRevenue) * 100).toFixed(1)}%\n\nThis indicates a ${budgetData.metadata.totalRevenue > budgetData.metadata.totalExpenses ? 'profitable' : 'challenging'} financial position. Would you like me to suggest optimization strategies?`;
      }
    }
    
    if (queryLower.includes('vision')) {
      const visionCompleteness = Object.values(vision).filter(v => v.length > 20).length / 5 * 100;
      return `Your strategic vision is ${visionCompleteness.toFixed(0)}% complete. ${context.length > 0 ? 'Key elements include:\n\n' + context.map(r => `• **${r.title}**: ${r.content.substring(0, 120)}...`).join('\n\n') : ''}\n\nA complete vision helps align all initiatives and projects. Which areas need more development?`;
    }
    
    // Default intelligent response
    if (context.length > 0) {
      return `Based on your query, I found ${context.length} relevant items:\n\n${context.map((r, i) => `${i + 1}. **${r.title}** (${r.type})\n   ${r.content.substring(0, 150)}...`).join('\n\n')}\n\nHow would you like to proceed with this information?`;
    }
    
    return "I understand you're asking about strategic planning. While I couldn't find specific matches in your current data, I can help you with:\n\n• **Vision Development**: Refining your strategic vision\n• **Initiative Analysis**: Evaluating and prioritizing initiatives\n• **Project Management**: Tracking progress and outcomes\n• **Budget Planning**: Financial analysis and optimization\n\nWhat specific area would you like to explore?";
  };

  const handleSendMessage = async () => {
    if (!inputValue.trim()) return;

    const userMessage: Message = {
      id: Date.now().toString(),
      type: 'user',
      content: inputValue,
      timestamp: new Date()
    };

    setMessages(prev => [...prev, userMessage]);
    setInputValue('');
    setIsLoading(true);

    try {
      // Perform RAG search
      const searchResults = await searchContext(inputValue);
      setSearchResults(searchResults);

      // Generate AI response
      const response = await generateResponse(inputValue, searchResults);

      const botMessage: Message = {
        id: (Date.now() + 1).toString(),
        type: 'bot',
        content: response,
        timestamp: new Date(),
        sources: searchResults,
        thinking: `Analyzed ${searchResults.length} relevant documents and data points`
      };

      setMessages(prev => [...prev, botMessage]);
    } catch (error) {
      toast.error('Failed to process your message');
      console.error('Bot error:', error);
    } finally {
      setIsLoading(false);
    }
  };

  const clearChat = () => {
    setMessages([
      {
        id: '1',
        type: 'bot',
        content: 'Chat cleared! How can I help you with your strategic planning?',
        timestamp: new Date()
      }
    ]);
    setSearchResults([]);
  };

  const getSourceIcon = (type: string) => {
    switch (type) {
      case 'vision': return Eye;
      case 'initiative': return Target;
      case 'project': return FileText;
      case 'budget': return DollarSign;
      default: return FileText;
    }
  };

  if (!isOpen) {
    return (
      <div className={cn('fixed bottom-6 right-6 z-50', className)}>
        <Button
          onClick={() => setIsOpen(true)}
          className="rounded-full w-14 h-14 bg-brand-brown hover:bg-brand-brown/90 shadow-lg"
        >
          <Bot className="w-6 h-6" />
        </Button>
      </div>
    );
  }

  return (
    <div className={cn(
      'fixed bottom-6 right-6 z-50 w-96 max-w-[calc(100vw-3rem)]',
      isMinimized ? 'h-14' : 'h-[600px]',
      className
    )}>
      <Card className="h-full flex flex-col shadow-xl border-2 border-brand-brown/20">
        {/* Header */}
        <CardHeader className="flex-shrink-0 bg-brand-brown text-white rounded-t-lg">
          <div className="flex items-center justify-between">
            <div className="flex items-center space-x-2">
              <Bot className="w-5 h-5" />
              <CardTitle className="text-lg">COTHINK'R Assistant</CardTitle>
              {isLoading && (
                <RefreshCw className="w-4 h-4 animate-spin" />
              )}
            </div>
            <div className="flex items-center space-x-1">
              <Button
                variant="ghost"
                size="sm"
                onClick={() => setIsMinimized(!isMinimized)}
                className="text-white hover:bg-white/20 w-8 h-8 p-0"
              >
                {isMinimized ? <Maximize2 className="w-4 h-4" /> : <Minimize2 className="w-4 h-4" />}
              </Button>
              <Button
                variant="ghost"
                size="sm"
                onClick={() => setIsOpen(false)}
                className="text-white hover:bg-white/20 w-8 h-8 p-0"
              >
                <X className="w-4 h-4" />
              </Button>
            </div>
          </div>
        </CardHeader>

        {!isMinimized && (
          <>
            {/* Messages */}
            <CardContent className="flex-1 p-0 flex flex-col min-h-0">
              <ScrollArea className="flex-1 p-4">
                <div className="space-y-4">
                  {messages.map((message) => (
                    <div
                      key={message.id}
                      className={cn(
                        'flex items-start space-x-2',
                        message.type === 'user' ? 'justify-end' : 'justify-start'
                      )}
                    >
                      {message.type === 'bot' && (
                        <div className="w-8 h-8 rounded-full bg-brand-brown flex items-center justify-center flex-shrink-0">
                          <Bot className="w-4 h-4 text-white" />
                        </div>
                      )}
                      
                      <div className={cn(
                        'max-w-[80%] rounded-lg px-3 py-2',
                        message.type === 'user' 
                          ? 'bg-brand-brown text-white ml-8' 
                          : 'bg-gray-100 text-gray-900'
                      )}>
                        <p className="text-sm whitespace-pre-wrap">{message.content}</p>
                        
                        {message.sources && message.sources.length > 0 && (
                          <div className="mt-3 space-y-2">
                            <Separator />
                            <div className="text-xs text-gray-600">
                              <div className="flex items-center space-x-1 mb-2">
                                <Search className="w-3 h-3" />
                                <span>Sources ({message.sources.length})</span>
                              </div>
                              {message.sources.map((source, index) => {
                                const SourceIcon = getSourceIcon(source.type);
                                return (
                                  <div key={index} className="flex items-center space-x-2 py-1">
                                    <SourceIcon className="w-3 h-3 text-gray-400 flex-shrink-0" />
                                    <span className="truncate">{source.title}</span>
                                    <Badge variant="secondary" className="text-xs">
                                      {(source.relevance * 100).toFixed(0)}%
                                    </Badge>
                                  </div>
                                );
                              })}
                            </div>
                          </div>
                        )}
                        
                        {message.thinking && (
                          <div className="mt-2 text-xs text-gray-500 flex items-center space-x-1">
                            <Sparkles className="w-3 h-3" />
                            <span>{message.thinking}</span>
                          </div>
                        )}
                      </div>
                      
                      {message.type === 'user' && (
                        <div className="w-8 h-8 rounded-full bg-gray-300 flex items-center justify-center flex-shrink-0">
                          <User className="w-4 h-4 text-gray-600" />
                        </div>
                      )}
                    </div>
                  ))}
                  
                  {isLoading && (
                    <div className="flex items-start space-x-2">
                      <div className="w-8 h-8 rounded-full bg-brand-brown flex items-center justify-center">
                        <Bot className="w-4 h-4 text-white" />
                      </div>
                      <div className="bg-gray-100 rounded-lg px-3 py-2">
                        <div className="flex space-x-1">
                          <div className="w-2 h-2 bg-gray-400 rounded-full animate-bounce" />
                          <div className="w-2 h-2 bg-gray-400 rounded-full animate-bounce" style={{ animationDelay: '0.1s' }} />
                          <div className="w-2 h-2 bg-gray-400 rounded-full animate-bounce" style={{ animationDelay: '0.2s' }} />
                        </div>
                      </div>
                    </div>
                  )}
                </div>
                <div ref={messagesEndRef} />
              </ScrollArea>
              
              {/* Input */}
              <div className="p-4 border-t border-gray-200">
                <div className="flex items-center space-x-2">
                  <Input
                    ref={inputRef}
                    value={inputValue}
                    onChange={(e) => setInputValue(e.target.value)}
                    placeholder="Ask about your strategic plan..."
                    onKeyPress={(e) => e.key === 'Enter' && !e.shiftKey && handleSendMessage()}
                    disabled={isLoading}
                    className="flex-1"
                  />
                  <Button
                    onClick={handleSendMessage}
                    disabled={!inputValue.trim() || isLoading}
                    size="sm"
                  >
                    <Send className="w-4 h-4" />
                  </Button>
                </div>
                
                <div className="flex justify-between items-center mt-2">
                  <div className="text-xs text-gray-500">
                    Powered by strategic context analysis
                  </div>
                  <Button
                    variant="ghost"
                    size="sm"
                    onClick={clearChat}
                    className="text-xs px-2 py-1 h-6"
                  >
                    Clear
                  </Button>
                </div>
              </div>
            </CardContent>
          </>
        )}
      </Card>
    </div>
  );
};

export default CothinkrBot;